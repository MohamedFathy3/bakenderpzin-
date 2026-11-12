<?php

namespace App\Http\Controllers;

use App\Helpers\JsonResponse;
use App\Http\Requests\AttendanceRequest;
use App\Http\Resources\AttendanceResource;
use App\Imports\AttendanceImport;
use App\Interfaces\AttendanceRepositoryInterface;
use App\Models\Attendance;
use Exception;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class AttendanceController extends BaseController
{

    protected mixed $crudRepository;

    public function __construct(AttendanceRepositoryInterface $pattern)
    {
        $this->crudRepository = $pattern;
    }

    public function index(Request $request)
    {
        try {
            $filters = $request->input('filters', []);
            $branchId = $filters['branch_id'] ?? $request->input('branch_id');
            $query = Attendance::with('employee');
            if ($branchId) {
                $query->whereHas('employee', function ($employeeQuery) use ($branchId) {
                    $employeeQuery->where('branch_id', $branchId);
                });
            }
            foreach (['employee_id', 'date', 'status'] as $field) {
                if (!empty($filters[$field])) $query->where($field, $filters[$field]);
            }
            if ($request->filled('date_from')) $query->whereDate('date', '>=', $request->input('date_from'));
            if ($request->filled('date_to')) $query->whereDate('date', '<=', $request->input('date_to'));
            if (!empty($filters['employee_name'])) {
                $query->whereHas('employee', function ($employeeQuery) use ($filters) {
                    $employeeQuery->where('name', 'like', '%' . $filters['employee_name'] . '%')
                        ->orWhere('name_ar', 'like', '%' . $filters['employee_name'] . '%');
                });
            }
            $query->orderBy($request->input('orderBy', 'id'), $request->input('orderByDirection', 'desc'));
            $rows = $request->boolean('paginate', true)
                ? $query->paginate((int) $request->input('perPage', 15))
                : $query->get();
            return AttendanceResource::collection($rows)->additional(JsonResponse::success());
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function store(AttendanceRequest $request)
    {
        try {
            $attendance = $this->crudRepository->create($request->validated());
            return new AttendanceResource($attendance);
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function show(Attendance $attendance): ?\Illuminate\Http\JsonResponse
    {
        try {
            return JsonResponse::respondSuccess('Item Fetched Successfully', new AttendanceResource($attendance));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }


    public function update(AttendanceRequest $request, Attendance $attendance)
    {
        try {
            $this->crudRepository->update($request->validated(), $attendance->id);
            activity()->performedOn($attendance)->withProperties(['attributes' => $attendance])->log('update');
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_UPDATED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }


    public function destroy(Request $request): ?\Illuminate\Http\JsonResponse
    {
        try {
            $this->crudRepository->deleteRecords('attendances', $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_DELETED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function restore(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $this->crudRepository->restoreItem(Attendance::class, $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_RESTORED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }




    public function forceDelete(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $this->crudRepository->deleteRecordsFinial(Attendance::class, $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_FORCE_DELETED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }


    public function importAttendance(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xlsl,csv'
        ]);

        try {
            Excel::import(new AttendanceImport, $request->file('file'));

            return response()->json([
                'status' => true,
                'message' => 'تم استيراد ملف الحضور بنجاح'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء الاستيراد',
                'error' => $e->getMessage()
            ], 500);
        }
    }

}
