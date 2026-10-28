<?php

namespace App\Http\Controllers;

use App\Http\Resources\CashierShiftResource;
use App\Models\Admin;
use App\Models\CashierShift;
use App\Models\Employee;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashierShiftController extends Controller
{
      public function openShift(Request $request)
    {
        $request->validate([
            'opening_balance' => 'required|numeric|min:0',
            'notes'           => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $shiftData = [
                'opening_balance' => $request->opening_balance,
                'notes'           => $request->notes,
                'opened_at'       => now(),
                'status'          => 'open',
            ];

        $user = $request->user();

        if ($user instanceof Admin) {
            $shiftData['admin_id'] = $user->id;
        } elseif ($user instanceof Employee) {
            $shiftData['employee_id'] = $user->id;
        } else {
            throw new \Exception('نوع المستخدم غير معروف');
        }


            $shift = CashierShift::create($shiftData);

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'تم فتح الورديه بنجاح',
                'data'    => new CashierShiftResource($shift)
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status'  => false,
                'message' => 'حدث خطأ أثناء فتح الورديه',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // اغلاق وردية
  public function closeShift(Request $request)
    {
        $request->validate([
            'actual_amount' => 'required|numeric',
            'notes'         => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $user = auth()->user();

            // جلب آخر وردية مفتوحة للشخص الحالي
            $shift = CashierShift::where('status', 'open')
                ->where(function ($q) use ($user) {
                    if ($user instanceof Admin) {
                        $q->where('admin_id', $user->id);
                    } elseif ($user instanceof Employee) {
                        $q->where('employee_id', $user->id);
                    }
                })
                ->latest('opened_at')
                ->lockForUpdate()
                ->first();

            if (!$shift) {
                return response()->json([
                    'status' => false,
                    'message' => 'لا توجد وردية مفتوحة حالياً'
                ], 404);
            }

            $expected = (
                $shift->opening_balance
                + $shift->cash_sales
                + $shift->card_sales
                + $shift->wallet_sales
                - $shift->returns_amount
                - $shift->expenses_amount
            );

            $shift->update([
                'closing_balance' => $request->actual_amount,
                'actual_amount'   => $request->actual_amount,
                'expected_amount' => $expected,
                'difference'      => $request->actual_amount - $expected,
                'status'          => 'closed',
                'closed_at'       => now(),
                'notes'           => $request->notes,
            ]);

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'تم إغلاق الورديه بنجاح',
                'data'    => new CashierShiftResource($shift->fresh())
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status'  => false,
                'message' => 'حدث خطأ أثناء إغلاق الورديه',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function storeExpense(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|gt:0|decimal:0,2',
            'description' => 'required|string|max:255',
        ]);

        $shift = DB::transaction(function () use ($request, $validated) {
            $user = $request->user();
            if (!$user instanceof Admin && !$user instanceof Employee) {
                throw ValidationException::withMessages([
                    'user' => 'لا يمكن تحديد مستخدم الوردية الحالي.',
                ]);
            }

            $shiftQuery = CashierShift::where('status', 'open')
                ->where(function ($query) use ($user) {
                    if ($user instanceof Admin) {
                        $query->where('admin_id', $user->id);
                    } elseif ($user instanceof Employee) {
                        $query->where('employee_id', $user->id);
                    }
                });

            $shift = $shiftQuery->latest('opened_at')->lockForUpdate()->first();
            if (!$shift) {
                throw ValidationException::withMessages([
                    'shift' => 'لا توجد وردية مفتوحة لك لتسجيل المصروف.',
                ]);
            }

            $employee = $user instanceof Employee
                ? $user
                : $shift->employee()->first();
            if (!$employee || !$employee->treasury_id) {
                throw ValidationException::withMessages([
                    'treasury' => 'لا توجد خزينة مرتبطة بموظف الوردية.',
                ]);
            }

            $treasury = Treasury::whereKey($employee->treasury_id)
                ->lockForUpdate()
                ->first();
            if (!$treasury) {
                throw ValidationException::withMessages([
                    'treasury' => 'تعذر العثور على خزينة موظف الوردية.',
                ]);
            }

            $amount = (float) $validated['amount'];
            if ((float) $treasury->balance < $amount) {
                throw ValidationException::withMessages([
                    'amount' => 'رصيد الخزينة غير كافٍ لتسجيل هذا المصروف.',
                ]);
            }

            $treasury->decrement('balance', $amount);
            $shift->increment('expenses_amount', $amount);

            TreasuryTransaction::create([
                'treasury_id' => $treasury->id,
                'reference_type' => CashierShift::class,
                'reference_id' => $shift->id,
                'type' => 'out',
                'amount' => $amount,
                'description' => "مصروف وردية #{$shift->id}: {$validated['description']}",
                'created_by_employee_id' => $employee->id,
            ]);

            return $shift->fresh();
        });

        return response()->json([
            'status' => true,
            'message' => 'تم تسجيل المصروف وخصمه من الخزينة والوردية.',
            'data' => new CashierShiftResource($shift),
        ]);
    }

    // عرض كل الورديات
    public function index(Request $request)
    {
        $shifts = CashierShift::with(['employee', 'admin'])
            ->when($request->filled('branch_id'), function ($query) use ($request) {
                $query->whereHas('employee', function ($q) use ($request) {
                    $q->where('branch_id', $request->branch_id);
                });
            })
            ->latest()
            ->get();

        return response()->json([
            'status' => true,
            'data'   => CashierShiftResource::collection($shifts)
        ]);
    }

    // عرض وردية واحدة
    public function show(cashierShift $shift)
    {
        $shift->load(['employee','admin']);
        return response()->json([
            'status' => true,
            'data'   => new CashierShiftResource($shift)
        ]);
    }


   public function getCurrentShift()
    {
        $user = auth()->user();

        $query = CashierShift::query()->where('status', 'open')
            ->where(function ($q) use ($user) {
                if ($user instanceof Admin) {
                    $q->where('admin_id', $user->id);
                } elseif ($user instanceof Employee) {
                    $q->where('employee_id', $user->id);
                }
            });

        $shift = $query->latest('opened_at')->first();

        if (!$shift) {
            return response()->json([
                'status' => false,
                'message' => 'لا توجد وردية مفتوحة حالياً'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'آخر وردية مفتوحة',
            'data' => $shift
        ]);
    }

}
