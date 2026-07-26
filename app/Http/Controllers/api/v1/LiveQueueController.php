<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LiveQueueStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Services\LiveQueueService;
use App\Models\LiveQueue;

use App\Http\Resources\LiveQueue\LiveQueueResource;

class LiveQueueController extends Controller
{
    protected $liveQueueService;

    public function __construct(LiveQueueService $liveQueueService)
    {
        $this->liveQueueService = $liveQueueService;
    }
    public function index(Request $request){
        $request->validate([
            "branch_id"=> "required|exists:branches,id",
        ]);

        $queue = $this->liveQueueService->getQueueForBranch($request->branch_id);

        return response()->json([
            'status' => 'success',
            'data'   => LiveQueueResource::collection($queue) // استخدام الـ Resource للكل
        ], 200);
    }

    public function Update (Request $request,$id){
        $request->validate([
            "status" => ["required", Rule::enum(LiveQueueStatus::class)],
        ]);
        $queue = $this->liveQueueService->updateStatus($id, $request->status);
        return response()->json([
            'status' => 'success',
            'data'   => new LiveQueueResource($queue) // استخدام الـ Resource للكل
        ], 200);
    }
public function destroy(string $id)
{
    $this->liveQueueService->destroyQueueItem($id);

    return response()->json([
        'status'  => 'success',
        'message' => 'Patient removed from waiting queue successfully',
    ], 200);
}
}

