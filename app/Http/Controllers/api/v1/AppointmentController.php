<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\AppointmentService;
// requests
use App\Http\Requests\Api\Appointment\StoreAppointmentRequest;
use App\Http\Requests\Api\Appointment\UpdateAppointmentRequest;
// resources
use App\Http\Resources\Appointments\AppointmentResource;
use App\Http\Resources\LiveQueue\LiveQueueResource;

class AppointmentController extends Controller
{
    private $appointmentService;
    public function __construct(AppointmentService $appointmentService){
        $this->appointmentService = $appointmentService;
    }


    public function index(Request $request)
    {

        $request->validate([
            "branch_id"=> "required|exists:branches,id",
            "date"      => "nullable|date_format:Y-m-d" 
        ]);

        $appointments = $this->appointmentService->getAllAppointmentsForBranch($request->branch_id, $request->date);

        return response()->json([
            "status"=> "success",
            "data"=> AppointmentResource::collection($appointments),          
        ],200);
    }

   public function store(StoreAppointmentRequest $request)
    {
        $appointment = $this->appointmentService->createAppointment($request->validated());

        $appointment->load('patient');

        return response()->json([
            "status" => "success",
            "data"   => new AppointmentResource($appointment),
        ], 201);
    }


    public function update( UpdateAppointmentRequest $request,$id){

      

        $appointment = $this->appointmentService->updateAppointment($id,$request->validated());

        return response()->json([
            "status" => "success",
            "data"   => new AppointmentResource($appointment),
        ], 200);
    }


    public function destroy($id){
        $appointment = $this->appointmentService->destroyAppointment($id);
        
            return response()->json([
            "status" => 'success',
            "message" => "Appointment deleted successfully"
        ], 200);
        
    }

    public function checkIn(string $id){
        $queueRecord = $this->appointmentService->checkInAppointment($id);

        return response()->json([
            "status"  => "success",
            "message" => "تم تأكيد حضور المريض ودخوله صالة الانتظار بنجاح",
            "data"    => new LiveQueueResource($queueRecord->load('patient'))
        ], 200);
    }
}
