<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadFollowUp;
use App\Models\LeadStatus;
use Illuminate\Http\Request;

class SalesExecutiveFollowUpController extends Controller
{
    /**
     * Get follow-up history for a specific assigned lead
     */
    public function index($leadId)
    {
        $user = auth()->user();

        // Security check: verify lead belongs to authenticated executive
        $lead = Lead::where('id', $leadId)
            ->where(function ($q) use ($user) {
                $q->where('assigned_to', $user->id)
                  ->orWhere('assigned_user_name', 'like', '%' . $user->name . '%');
            })
            ->first();

        if (!$lead) {
            return response()->json([
                'status' => false,
                'message' => 'Lead not found or you do not have permission to view its follow-ups.',
            ], 404);
        }

        $followUps = LeadFollowUp::with('user')
            ->where('lead_id', $leadId)
            ->latest('id')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Follow-up history fetched successfully',
            'data' => $followUps,
        ]);
    }

    /**
     * Create/log a new follow-up interaction on an assigned lead
     */
    public function store(Request $request, $leadId)
    {
        $user = auth()->user();

        // Security check: verify lead belongs to authenticated executive
        $lead = Lead::where('id', $leadId)
            ->where(function ($q) use ($user) {
                $q->where('assigned_to', $user->id)
                  ->orWhere('assigned_user_name', 'like', '%' . $user->name . '%');
            })
            ->first();

        if (!$lead) {
            return response()->json([
                'status' => false,
                'message' => 'Lead not found or you do not have permission to add a follow-up.',
            ], 404);
        }

        $request->validate([
            'follow_up_date' => 'required|date',
            'follow_up_time' => 'nullable|string|max:50',
            'type' => 'required|string|max:100',
            'notes' => 'nullable|string|max:3000',
            'next_follow_up_date' => 'nullable|date',
            'next_follow_up_time' => 'nullable|string|max:50',
            'status' => 'required|string|max:50',
            'lead_status_id' => 'nullable|exists:lead_statuses,id',
            'lead_status_name' => 'nullable|string|max:100',
        ]);

        // Auto-assign user_id from authenticated token
        $followUp = LeadFollowUp::create([
            'lead_id' => $lead->id,
            'user_id' => $user->id,
            'follow_up_date' => $request->follow_up_date,
            'follow_up_time' => $request->follow_up_time,
            'type' => $request->type,
            'notes' => $request->notes,
            'next_follow_up_date' => $request->next_follow_up_date,
            'next_follow_up_time' => $request->next_follow_up_time,
            'status' => ucfirst(strtolower($request->status)),
        ]);

        // If lead status updated as part of follow-up interaction
        if ($request->filled('lead_status_id') || $request->filled('lead_status_name')) {
            $statusName = $request->lead_status_name;
            $statusId = $request->lead_status_id;

            if ($statusId && !$statusName) {
                $st = LeadStatus::find($statusId);
                $statusName = $st ? $st->name : null;
            } elseif ($statusName && !$statusId) {
                $st = LeadStatus::where('name', $statusName)->first();
                $statusId = $st ? $st->id : null;
            }

            if ($statusName || $statusId) {
                $lead->update([
                    'status_id' => $statusId ?: $lead->status_id,
                    'status_name' => $statusName ?: $lead->status_name,
                ]);
            }
        }

        $followUp->load('user');

        return response()->json([
            'status' => true,
            'message' => 'Follow-up logged successfully',
            'data' => $followUp,
        ], 201);
    }
}
