<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\LeadAssignmentHistoryController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LeadSourceController;
use App\Http\Controllers\SalesExecutiveFollowUpController;
use App\Http\Controllers\SalesExecutiveLeadController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VehicleModelController;
use App\Http\Controllers\VehicleVariantController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Authentication API
Route::post('/auth/login', [AuthController::class, 'login']);

// Sales Executive Dedicated APIs (Strictly scoped to authenticated user)
Route::middleware('auth:sanctum')->prefix('sales-executive')->group(function () {
    Route::get('/leads', [SalesExecutiveLeadController::class, 'index']);
    Route::get('/leads/{lead}', [SalesExecutiveLeadController::class, 'show']);
    Route::get('/leads/{lead}/follow-ups', [SalesExecutiveFollowUpController::class, 'index']);
    Route::post('/leads/{lead}/follow-ups', [SalesExecutiveFollowUpController::class, 'store']);
});

// Lead Sources Master CRUD API
Route::apiResource('lead-sources', LeadSourceController::class);

// Lead Statuses Master CRUD API
Route::apiResource('lead-statuses', LeadStatusController::class);

// Brand Master CRUD API
Route::apiResource('brands', BrandController::class);

// Vehicle Model Master CRUD API
Route::apiResource('models', VehicleModelController::class);

// Vehicle Variant Master CRUD API
Route::apiResource('variants', VehicleVariantController::class);

// Customer Leads Bulk Operations API
Route::post('leads/bulk-delete', [LeadController::class, 'bulkDelete']);
Route::post('leads/bulk-status', [LeadController::class, 'bulkStatus']);
Route::post('leads/bulk-priority', [LeadController::class, 'bulkPriority']);
Route::post('leads/bulk-assign', [LeadController::class, 'bulkAssign']);

// Specific Lead Assignment History Route
Route::get('leads/{id}/assignments', [LeadAssignmentHistoryController::class, 'getByLead']);

// Lead Assignment History Master CRUD API
Route::apiResource('lead-assignments', LeadAssignmentHistoryController::class);

// Customer Leads Master CRUD API
Route::apiResource('leads', LeadController::class);

// Users Master CRUD API
Route::apiResource('users', UserController::class);


