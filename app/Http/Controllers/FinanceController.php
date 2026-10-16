<?php

namespace App\Http\Controllers;

use App\Helpers\JsonResponse;
use App\Http\Requests\FinanceRequest;
use App\Http\Resources\FinanceResource;
use App\Interfaces\FinanceRepositoryInterface;
use App\Models\Finance;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FinanceController extends BaseController
{
    protected mixed $crudRepository;

    public function __construct(FinanceRepositoryInterface $pattern)
    {
        $this->crudRepository = $pattern;
    }

    public function index()
    {
        try {
            $finances = $this->crudRepository->all(
                [],
                ['treasury', 'currency', 'branch'],
                ['*']
            );
            
            $Finance = FinanceResource::collection($finances);
            return $Finance->additional(JsonResponse::success());
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    // ✅ دالة الإضافة (بتخصم من الخزينة)
    public function store(FinanceRequest $request)
    {
        DB::beginTransaction();
        try {
            // 1. إنشاء المصروف
            $finance = $this->crudRepository->create($request->validated());

            // 2. إذا كان فيه خزينة -> يخصم منها
            if ($request->filled('treasury_id')) {
                $this->decreaseTreasuryBalance($finance);
            }

            DB::commit();
            
            // 3. تحميل العلاقات للرد
            $finance->load(['treasury', 'currency', 'branch']);
            
            return new FinanceResource($finance);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Finance store error: ' . $e->getMessage());
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function show(Finance $Finance): ?\Illuminate\Http\JsonResponse
    {
        try {
            $Finance->load(['treasury', 'currency', 'branch']);
            return JsonResponse::respondSuccess('Item Fetched Successfully', new FinanceResource($Finance));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    // ✅ دالة التعديل (بتتعامل مع تغيير الخزينة أو المبلغ بذكاء)
    public function update(FinanceRequest $request, Finance $Finance)
    {
        if ($Finance->purchase_invoice_id) {
            return JsonResponse::respondError('يتم تحديث هذا المصروف تلقائياً من فاتورة المشتريات');
        }

        DB::beginTransaction();
        try {
            // 1. الحصول على القيم القديمة قبل التعديل
            $oldTreasuryId = $Finance->getOriginal('treasury_id');
            $oldAmount = $Finance->getOriginal('amount');
            
            // 2. القيم الجديدة من الريكويست
            $newTreasuryId = $request->treasury_id;
            $newAmount = $request->amount;

            // 3. التعامل مع الخزائن لو اتغيرت
            // (أ) لو كان فيه خزينة قديمة ومش موجودة في الجديد -> نرجع الفلوس للخزينة القديمة
            if ($oldTreasuryId && (!$newTreasuryId || $oldTreasuryId != $newTreasuryId)) {
                $this->increaseTreasuryBalance($Finance, $oldTreasuryId, $oldAmount);
            }

            // (ب) لو في خزينة جديدة ومختلفة عن القديمة -> نخصم من الخزينة الجديدة
            if ($newTreasuryId && $newTreasuryId != $oldTreasuryId) {
                // هنا بنعمل كأننا بننشئ مصروف جديد للخزينة الجديدة بنفس المبلغ
                $tempFinance = clone $Finance;
                $tempFinance->treasury_id = $newTreasuryId;
                $tempFinance->amount = $newAmount;
                $this->decreaseTreasuryBalance($tempFinance);
            }
            // (ج) لو نفس الخزينة بس المبلغ اتغير (زيادة أو نقصان)
            elseif ($oldTreasuryId && $newTreasuryId == $oldTreasuryId && $newAmount != $oldAmount) {
                // نحسب الفرق ونعدل الرصيد
                $difference = $newAmount - $oldAmount;
                $this->adjustTreasuryBalance($Finance, $difference);
            }

            // 4. تحديث البيانات الأساسية في الداتابيس
            $this->crudRepository->update($request->validated(), $Finance->id);

            activity()->performedOn($Finance)->withProperties(['attributes' => $Finance])->log('update');
            DB::commit();
            
            $Finance->load(['treasury', 'currency', 'branch']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_UPDATED_SUCCESSFULLY));
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Finance update error: ' . $e->getMessage());
            return JsonResponse::respondError($e->getMessage());
        }
    }

    // ✅ دالة الحذف (بتعيد المبلغ للخزينة)
    public function destroy(Request $request): ?\Illuminate\Http\JsonResponse
    {
        DB::beginTransaction();
        try {
            $items = $request['items'];
            foreach ($items as $id) {
                $finance = Finance::find($id);
                if ($finance) {
                    if ($finance->purchase_invoice_id) {
                        throw new Exception('يتم إدارة مصروف فاتورة المشتريات من الفاتورة نفسها');
                    }

                    // ✅ إعادة الرصيد للخزينة عند حذف المصروف
                    if ($finance->treasury_id) {
                        $this->increaseTreasuryBalance($finance, $finance->treasury_id, $finance->amount);
                    }
                    
                    TreasuryTransaction::where('reference_type', Finance::class)
                        ->where('reference_id', $finance->id)
                        ->delete();
                }
            }

            $this->crudRepository->deleteRecords('finances', $items);
            DB::commit();
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_DELETED_SUCCESSFULLY));
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Finance delete error: ' . $e->getMessage());
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function restore(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $hasPurchaseExpense = Finance::withTrashed()
                ->whereIn('id', $request['items'])
                ->whereNotNull('purchase_invoice_id')
                ->exists();

            if ($hasPurchaseExpense) {
                return JsonResponse::respondError('يتم إدارة مصروف فاتورة المشتريات من الفاتورة نفسها');
            }

            $this->crudRepository->restoreItem(Finance::class, $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_RESTORED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function forceDelete(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $hasPurchaseExpense = Finance::withTrashed()
                ->whereIn('id', $request['items'])
                ->whereNotNull('purchase_invoice_id')
                ->exists();

            if ($hasPurchaseExpense) {
                return JsonResponse::respondError('يتم إدارة مصروف فاتورة المشتريات من الفاتورة نفسها');
            }

            $this->crudRepository->deleteRecordsFinial(Finance::class, $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_FORCE_DELETED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    // =============================================
    // ✅ دوال مساعدة للتعامل مع الخزينة (Treasury Logic)
    // =============================================

    /**
     * خصم المبلغ من الخزينة (في حالة الإضافة أو تغيير الخزينة)
     */
    private function decreaseTreasuryBalance($finance)
    {
        if (!$finance->treasury_id) return;

        $treasury = Treasury::find($finance->treasury_id);
        if (!$treasury) {
            Log::warning("⚠️ Treasury not found for finance: {$finance->id}");
            return;
        }

        $amount = $finance->amount;
        
        // ✅ المصروف يقلل الرصيد
        $treasury->decrement('balance', $amount);
        
        // ✅ تسجيل حركة الخزينة (out)
        TreasuryTransaction::create([
            'treasury_id' => $finance->treasury_id,
            'reference_type' => Finance::class,
            'reference_id' => $finance->id,
            'type' => 'out',
            'amount' => $amount,
            'description' => "خصم مصروف: {$finance->category} - " . ($finance->description ?? 'بدون وصف'),
        ]);

        Log::info("💰 Treasury balance decreased", [
            'finance_id' => $finance->id,
            'treasury_id' => $finance->treasury_id,
            'amount' => $amount,
            'new_balance' => $treasury->balance,
        ]);
    }

    /**
     * زيادة المبلغ في الخزينة (في حالة الحذف أو إزالة الخزينة)
     */
    private function increaseTreasuryBalance($finance, $treasuryId = null, $amount = null)
    {
        $treasuryId = $treasuryId ?? $finance->treasury_id;
        $amount = $amount ?? $finance->amount;

        if (!$treasuryId) return;

        $treasury = Treasury::find($treasuryId);
        if (!$treasury) return;

        $treasury->increment('balance', $amount);

        // ✅ تسجيل حركة الخزينة (in)
        TreasuryTransaction::create([
            'treasury_id' => $treasuryId,
            'reference_type' => Finance::class,
            'reference_id' => $finance->id,
            'type' => 'in',
            'amount' => $amount,
            'description' => "استرجاع مصروف: {$finance->category} - " . ($finance->description ?? 'بدون وصف'),
        ]);

        Log::info("💰 Treasury balance increased", [
            'finance_id' => $finance->id,
            'treasury_id' => $treasuryId,
            'amount' => $amount,
            'new_balance' => $treasury->balance,
        ]);
    }

    /**
     * تعديل الرصيد بناءً على الفرق في المبلغ (نفس الخزينة)
     */
    private function adjustTreasuryBalance($finance, $difference)
    {
        if (!$finance->treasury_id || $difference == 0) return;

        $treasury = Treasury::find($finance->treasury_id);
        if (!$treasury) return;

        if ($difference > 0) {
            // لو المبلغ زاد -> نخصم الزيادة
            $treasury->decrement('balance', $difference);
            $type = 'out';
            $desc = "تعديل مصروف (زيادة): {$finance->category}";
        } else {
            // لو المبلغ قل -> نرجع النقصان
            $treasury->increment('balance', abs($difference));
            $type = 'in';
            $desc = "تعديل مصروف (نقصان): {$finance->category}";
        }

        TreasuryTransaction::create([
            'treasury_id' => $finance->treasury_id,
            'reference_type' => Finance::class,
            'reference_id' => $finance->id,
            'type' => $type,
            'amount' => abs($difference),
            'description' => $desc,
        ]);
    }
}