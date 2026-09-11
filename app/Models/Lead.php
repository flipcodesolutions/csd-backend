<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'city',
        'state',
        'vehicle_segment',
        'brand_id',
        'brand_name',
        'model_variant',
        'priority',
        'purchase_timeline',
        'source_id',
        'source_name',
        'status_id',
        'status_name',
        'assigned_to',
        'assigned_user_name',
    ];

    /**
     * Computed dynamic attributes appended to JSON serialization
     */
    protected $appends = [
        'assigned_to_display',
        'assigned_by_display',
        'assigned_by_user',
    ];

    /**
     * Relationship: Lead belongs to a Brand
     */
    public function brand()
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    /**
     * Relationship: Lead belongs to a Lead Source
     */
    public function source()
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }

    /**
     * Relationship: Lead belongs to a Lead Status
     */
    public function status()
    {
        return $this->belongsTo(LeadStatus::class, 'status_id');
    }

    /**
     * Relationship: Lead assigned to a User
     */
    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Relationship: Lead has many assignment histories
     */
    public function assignmentHistories()
    {
        return $this->hasMany(LeadAssignmentHistory::class, 'lead_id')->latest();
    }

    /**
     * Relationship: Latest assignment history record
     */
    public function latestAssignment()
    {
        return $this->hasOne(LeadAssignmentHistory::class, 'lead_id')->latestOfMany();
    }

    /**
     * Relationship: Lead has many follow-ups
     */
    public function followUps()
    {
        return $this->hasMany(LeadFollowUp::class, 'lead_id')->latest();
    }

    /**
     * Relationship: Latest follow-up record
     */
    public function latestFollowUp()
    {
        return $this->hasOne(LeadFollowUp::class, 'lead_id')->latestOfMany();
    }

    /**
     * Accessor: Formatted assigned_to user or '-' if unassigned
     */
    public function getAssignedToDisplayAttribute()
    {
        if ($this->assignedUser) {
            return $this->assignedUser->role 
                ? "{$this->assignedUser->name} ({$this->assignedUser->role})" 
                : $this->assignedUser->name;
        }

        if (!empty($this->assigned_user_name) && trim($this->assigned_user_name) !== '') {
            return $this->assigned_user_name;
        }

        return '-';
    }

    /**
     * Accessor: Formatted assigned_by user or '-' if unassigned
     */
    public function getAssignedByDisplayAttribute()
    {
        if ($this->latestAssignment && $this->latestAssignment->assignedByUser) {
            $user = $this->latestAssignment->assignedByUser;
            return $user->role 
                ? "{$user->name} ({$user->role})" 
                : $user->name;
        }

        return '-';
    }

    /**
     * Accessor: Assigned by User relation object or null
     */
    public function getAssignedByUserAttribute()
    {
        return $this->latestAssignment?->assignedByUser;
    }
}
