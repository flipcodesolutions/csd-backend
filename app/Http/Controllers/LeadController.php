<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Lead;
use App\Models\LeadAssignmentHistory;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\User;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    /**
     * Get list of all leads with associated relationships & filters
     * Supports backend role-based access control for authenticated user
     */
    public function index(Request $request)
    {
        $user = $request->user('sanctum') ?? auth('sanctum')->user() ?? auth()->user();

        $query = Lead::with([
            'brand',
            'source',
            'status',
            'assignedUser',
            'latestAssignment.assignedByUser',
            'latestAssignment.assignedToUser',
        ]);

        // Backend Role-Based Lead Access:
        // Sales Executive receives only their assigned leads
        if ($user && in_array(strtolower(str_replace(' ', '_', $user->role)), ['sales_executive', 'sales_rep', 'sales_consultant'])) {
            $query->where(function ($q) use ($user) {
                $q->where('assigned_to', $user->id)
                  ->orWhere('assigned_user_name', 'like', '%' . $user->name . '%');
            });
        }

        // Search across customer name, phone, email, model/variant, city, brand
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                  ->orWhere('phone', 'like', '%' . $search . '%')
                  ->orWhere('email', 'like', '%' . $search . '%')
                  ->orWhere('model_variant', 'like', '%' . $search . '%')
                  ->orWhere('city', 'like', '%' . $search . '%')
                  ->orWhere('brand_name', 'like', '%' . $search . '%');
            });
        }

        // Filter by Priority (Hot / Warm / Cold)
        if ($request->filled('priority')) {
            $query->where('priority', ucfirst(strtolower($request->priority)));
        }

        // Filter by Vehicle Segment (2 Wheeler / 4 Wheeler)
        if ($request->filled('vehicle_segment')) {
            $query->where('vehicle_segment', $request->vehicle_segment);
        }

        // Filter by Status Name or Status ID
        if ($request->filled('status')) {
            $query->where('status_name', $request->status);
        }
        if ($request->filled('status_id')) {
            $query->where('status_id', $request->status_id);
        }

        // Filter by Brand
        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->brand_id);
        }

        // Filter by Source
        if ($request->filled('source_id')) {
            $query->where('source_id', $request->source_id);
        }

        // Filter by Assigned User (Admin/Manager filter)
        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }

        // Handle Pagination or Full List
        $perPage = (int) $request->get('per_page', 0);
        if ($perPage > 0 || $request->filled('page')) {
            $perPage = $perPage > 0 ? $perPage : 15;
            $paginated = $query->latest()->paginate($perPage);

            return response()->json([
                'status' => true,
                'message' => 'Leads retrieved successfully',
                'data' => $paginated->items(),
                'pagination' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ],
            ]);
        }

        $leads = $query->latest()->get();

        return response()->json([
            'status' => true,
            'message' => 'Leads retrieved successfully',
            'data' => $leads,
            'pagination' => [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => count($leads),
                'total' => count($leads),
            ],
        ]);
    }

    /**
     * Create a new customer lead
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'birth_date' => 'nullable|date',
            'anniversary_date' => 'nullable|date',
            'vehicle_segment' => 'required|string|in:2 Wheeler,4 Wheeler,2 wheeler,4 wheeler',
            'brand_id' => 'nullable|exists:brands,id',
            'brand_name' => 'nullable|string|max:255',
            'model_variant' => 'required|string|max:255',
            'priority' => 'required|string|in:Hot,Warm,Cold,hot,warm,cold',
            'purchase_timeline' => 'nullable|string|max:100',
            'source_id' => 'nullable|exists:lead_sources,id',
            'source_name' => 'nullable|string|max:100',
            'status_id' => 'nullable|exists:lead_statuses,id',
            'status_name' => 'nullable|string|max:100',
            'assigned_to' => 'nullable',
            'assigned_user_name' => 'nullable|string|max:100',
        ]);

        // Auto-fill brand name if brand_id provided
        $brandName = $request->brand_name;
        if ($request->filled('brand_id') && !$brandName) {
            $brand = Brand::find($request->brand_id);
            $brandName = $brand ? $brand->name : null;
        }

        // Auto-fill source name if source_id provided
        $sourceName = $request->source_name;
        if ($request->filled('source_id') && !$sourceName) {
            $source = LeadSource::find($request->source_id);
            $sourceName = $source ? $source->title : null;
        }

        // Auto-fill status name if status_id provided
        $statusName = $request->status_name ?: 'New';
        if ($request->filled('status_id') && !$request->filled('status_name')) {
            $status = LeadStatus::find($request->status_id);
            $statusName = $status ? $status->name : 'New';
        }

        [$assignedToId, $assignedUserName] = $this->resolveAssignToUser($request->assigned_to, $request->assigned_user_name);
        $assignById = $this->resolveAssignByUserId($request->assign_by);

        $lead = Lead::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'city' => $request->city,
            'state' => $request->state,
            'birth_date' => $request->birth_date,
            'anniversary_date' => $request->anniversary_date,
            'vehicle_segment' => $request->vehicle_segment,
            'brand_id' => $request->brand_id,
            'brand_name' => $brandName,
            'model_variant' => $request->model_variant,
            'priority' => ucfirst(strtolower($request->priority)),
            'purchase_timeline' => $request->purchase_timeline,
            'source_id' => $request->source_id,
            'source_name' => $sourceName,
            'status_id' => $request->status_id,
            'status_name' => $statusName,
            'assigned_to' => $assignedToId,
            'assigned_user_name' => $assignedUserName,
        ]);

        // Automatically log initial assignment history with resolved user IDs
        if ($assignedToId) {
            LeadAssignmentHistory::create([
                'lead_id' => $lead->id,
                'assign_to' => $assignedToId,
                'assign_by' => $assignById,
                'remarks' => $request->remarks ?: 'Initial lead creation assignment',
            ]);
        }

        $lead->load([
            'brand',
            'source',
            'status',
            'assignedUser',
            'latestAssignment.assignedByUser',
            'latestAssignment.assignedToUser',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Customer lead created successfully',
            'data' => $lead,
        ], 201);
    }

    /**
     * Get a single customer lead
     */
    public function show(Request $request, $id)
    {
        $user = $request->user('sanctum') ?? auth('sanctum')->user() ?? auth()->user();

        $lead = Lead::with([
            'brand',
            'source',
            'status',
            'assignedUser',
            'latestAssignment.assignedByUser',
            'latestAssignment.assignedToUser',
            'followUps.user',
            'assignmentHistories.assignedByUser',
        ])->find($id);

        if (!$lead) {
            return response()->json([
                'status' => false,
                'message' => 'Lead not found',
            ], 404);
        }

        // Scope check for Sales Executive
        if ($user && in_array(strtolower(str_replace(' ', '_', $user->role)), ['sales_executive', 'sales_rep', 'sales_consultant'])) {
            $isOwner = ($lead->assigned_to == $user->id) || (!empty($lead->assigned_user_name) && stripos($lead->assigned_user_name, $user->name) !== false);
            if (!$isOwner) {
                return response()->json([
                    'status' => false,
                    'message' => 'You do not have permission to access this lead.',
                ], 403);
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Lead details',
            'data' => $lead,
        ]);
    }

    /**
     * Update an existing customer lead
     */
    public function update(Request $request, $id)
    {
        $user = $request->user('sanctum') ?? auth('sanctum')->user() ?? auth()->user();
        $lead = Lead::find($id);

        if (!$lead) {
            return response()->json([
                'status' => false,
                'message' => 'Lead not found',
            ], 404);
        }

        // Scope check for Sales Executive
        if ($user && in_array(strtolower(str_replace(' ', '_', $user->role)), ['sales_executive', 'sales_rep', 'sales_consultant'])) {
            $isOwner = ($lead->assigned_to == $user->id) || (!empty($lead->assigned_user_name) && stripos($lead->assigned_user_name, $user->name) !== false);
            if (!$isOwner) {
                return response()->json([
                    'status' => false,
                    'message' => 'You do not have permission to update this lead.',
                ], 403);
            }
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'birth_date' => 'nullable|date',
            'anniversary_date' => 'nullable|date',
            'vehicle_segment' => 'required|string|in:2 Wheeler,4 Wheeler,2 wheeler,4 wheeler',
            'brand_id' => 'nullable|exists:brands,id',
            'brand_name' => 'nullable|string|max:255',
            'model_variant' => 'required|string|max:255',
            'priority' => 'required|string|in:Hot,Warm,Cold,hot,warm,cold',
            'purchase_timeline' => 'nullable|string|max:100',
            'source_id' => 'nullable|exists:lead_sources,id',
            'source_name' => 'nullable|string|max:100',
            'status_id' => 'nullable|exists:lead_statuses,id',
            'status_name' => 'nullable|string|max:100',
            'assigned_to' => 'nullable',
            'assigned_user_name' => 'nullable|string|max:100',
        ]);

        // Auto-fill brand name if brand_id provided
        $brandName = $request->brand_name ?: $lead->brand_name;
        if ($request->filled('brand_id')) {
            $brand = Brand::find($request->brand_id);
            $brandName = $brand ? $brand->name : $brandName;
        }

        // Auto-fill source name if source_id provided
        $sourceName = $request->source_name ?: $lead->source_name;
        if ($request->filled('source_id')) {
            $source = LeadSource::find($request->source_id);
            $sourceName = $source ? $source->title : $sourceName;
        }

        // Auto-fill status name if status_id provided
        $statusName = $request->status_name ?: $lead->status_name;
        if ($request->filled('status_id')) {
            $status = LeadStatus::find($request->status_id);
            $statusName = $status ? $status->name : $statusName;
        }

        [$assignedToId, $assignedUserName] = $this->resolveAssignToUser(
            $request->filled('assigned_to') ? $request->assigned_to : $lead->assigned_to,
            $request->filled('assigned_user_name') ? $request->assigned_user_name : $lead->assigned_user_name
        );
        $assignById = $this->resolveAssignByUserId($request->assign_by);

        $isReassigned = ($assignedToId && $lead->assigned_to !== $assignedToId) ||
                        ($assignedUserName && $lead->assigned_user_name !== $assignedUserName);

        $lead->update([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'city' => $request->city,
            'state' => $request->state,
            'birth_date' => $request->has('birth_date') ? $request->birth_date : $lead->birth_date,
            'anniversary_date' => $request->has('anniversary_date') ? $request->anniversary_date : $lead->anniversary_date,
            'vehicle_segment' => $request->vehicle_segment,
            'brand_id' => $request->brand_id,
            'brand_name' => $brandName,
            'model_variant' => $request->model_variant,
            'priority' => ucfirst(strtolower($request->priority)),
            'purchase_timeline' => $request->purchase_timeline,
            'source_id' => $request->source_id,
            'source_name' => $sourceName,
            'status_id' => $request->status_id,
            'status_name' => $statusName,
            'assigned_to' => $assignedToId,
            'assigned_user_name' => $assignedUserName,
        ]);

        // If assignment changed, log to assignment history
        if ($isReassigned && $assignedToId) {
            LeadAssignmentHistory::create([
                'lead_id' => $lead->id,
                'assign_to' => $assignedToId,
                'assign_by' => $assignById,
                'remarks' => $request->assignment_remarks ?: $request->remarks ?: 'Lead executive reassignment',
            ]);
        }

        $lead->load([
            'brand',
            'source',
            'status',
            'assignedUser',
            'latestAssignment.assignedByUser',
            'latestAssignment.assignedToUser',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Customer lead updated successfully',
            'data' => $lead,
        ]);
    }

    /**
     * Delete a customer lead
     */
    public function destroy($id)
    {
        $lead = Lead::find($id);

        if (!$lead) {
            return response()->json([
                'status' => false,
                'message' => 'Lead not found',
            ], 404);
        }

        $lead->delete();

        return response()->json([
            'status' => true,
            'message' => 'Customer lead deleted successfully',
        ]);
    }

    /**
     * Bulk Delete multiple customer leads
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $count = Lead::whereIn('id', $request->ids)->delete();

        return response()->json([
            'status' => true,
            'message' => "Successfully deleted {$count} selected leads.",
            'deleted_count' => $count,
        ]);
    }

    /**
     * Bulk Update Status for multiple leads
     */
    public function bulkStatus(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'status_id' => 'nullable|exists:lead_statuses,id',
            'status_name' => 'nullable|string|max:100',
        ]);

        $statusName = $request->status_name;
        $statusId = $request->status_id;

        if ($statusId && !$statusName) {
            $status = LeadStatus::find($statusId);
            $statusName = $status ? $status->name : 'New';
        } elseif ($statusName && !$statusId) {
            $status = LeadStatus::where('name', $statusName)->first();
            $statusId = $status ? $status->id : null;
        }

        $count = Lead::whereIn('id', $request->ids)->update([
            'status_id' => $statusId,
            'status_name' => $statusName ?: 'New',
        ]);

        return response()->json([
            'status' => true,
            'message' => "Updated status for {$count} selected leads to '{$statusName}'.",
            'updated_count' => $count,
        ]);
    }

    /**
     * Bulk Update Priority for multiple leads
     */
    public function bulkPriority(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'priority' => 'required|string|in:Hot,Warm,Cold,hot,warm,cold',
        ]);

        $priority = ucfirst(strtolower($request->priority));
        $count = Lead::whereIn('id', $request->ids)->update([
            'priority' => $priority,
        ]);

        return response()->json([
            'status' => true,
            'message' => "Updated priority for {$count} selected leads to '{$priority}'.",
            'updated_count' => $count,
        ]);
    }

    /**
     * Bulk Assign Executive for multiple leads
     */
    public function bulkAssign(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'assigned_to' => 'nullable',
            'assigned_user_name' => 'nullable|string|max:255',
            'assign_by' => 'nullable',
            'remarks' => 'nullable|string|max:1000',
        ]);

        [$assignedToId, $assignedUserName] = $this->resolveAssignToUser($request->assigned_to, $request->assigned_user_name);
        $assignById = $this->resolveAssignByUserId($request->assign_by);

        $count = Lead::whereIn('id', $request->ids)->update([
            'assigned_to' => $assignedToId,
            'assigned_user_name' => $assignedUserName,
        ]);

        // Bulk record assignment history
        $historyData = [];
        $now = now();

        foreach ($request->ids as $leadId) {
            $historyData[] = [
                'lead_id' => $leadId,
                'assign_to' => $assignedToId,
                'assign_by' => $assignById,
                'remarks' => $request->remarks ?: 'Bulk lead executive assignment',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (!empty($historyData)) {
            LeadAssignmentHistory::insert($historyData);
        }

        return response()->json([
            'status' => true,
            'message' => "Assigned {$count} selected leads to '{$assignedUserName}'.",
            'updated_count' => $count,
            'assigned_to' => $assignedToId,
            'assign_by' => $assignById,
        ]);
    }

    /**
     * Helper to resolve valid assign_to user ID and name
     */
    protected function resolveAssignToUser($assignedTo, $assignedUserName)
    {
        $userId = null;
        $userName = $assignedUserName;

        if ($assignedTo && is_numeric($assignedTo)) {
            $user = User::find($assignedTo);
            if ($user) {
                $userId = $user->id;
                $userName = $userName ?: ($user->role ? "{$user->name} ({$user->role})" : $user->name);
            }
        }

        if (!$userId && $userName) {
            $nameQuery = trim(preg_replace('/\(.*?\)/', '', $userName));
            $user = User::where('name', 'like', "%{$nameQuery}%")->first();
            if ($user) {
                $userId = $user->id;
            }
        }

        if (!$userId) {
            $user = User::where('status', 'Active')->first() ?? User::first();
            if ($user) {
                $userId = $user->id;
                if (!$userName) {
                    $userName = $user->role ? "{$user->name} ({$user->role})" : $user->name;
                }
            }
        }

        return [$userId, $userName];
    }

    /**
     * Trigger Birthday & Anniversary Greetings dispatch on-demand via API
     */
    public function sendGreetingsNow(Request $request)
    {
        $dryRun = $request->boolean('dry_run', false);
        $force = $request->boolean('force', false);

        $params = [];
        if ($dryRun) $params['--dry-run'] = true;
        if ($force) $params['--force'] = true;

        try {
            \Illuminate\Support\Facades\Artisan::call('leads:send-wishes', $params);
            $output = \Illuminate\Support\Facades\Artisan::output();

            return response()->json([
                'status' => true,
                'message' => 'Greetings automation executed successfully.',
                'dry_run' => $dryRun,
                'force' => $force,
                'output' => $output,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to execute greetings automation: ' . $e->getMessage(),
            ], 500);
        }
    }
}
