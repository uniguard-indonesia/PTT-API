<?php

use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\LevelController;
use App\Http\Controllers\Admin\LogActivitiesController;
use App\Http\Controllers\Admin\ServerController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', [AuthController::class, 'index'])->name('auth');
Route::post('/login', [AuthController::class, 'login'])->name('auth.login');

Route::middleware('auth')->group(function(){
    Route::get('/logout', [AuthController::class, 'logout'])->name('auth.logout');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('auth.dashboard');
    Route::get('/dashboard/markers', [DashboardController::class, 'markersUser'])->name('auth.dashboard.markers');
    
    // Profile Routes
    Route::get('/profile', [ProfileController::class, 'index'])->name('profile');
    Route::post('/profile/update', [ProfileController::class, 'update'])->name('profile.update');
    
    Route::prefix('admin')->name('admin.')->group(function(){
        //User
        Route::resource('user', UserController::class)->only(['index', 'store', 'edit', 'update', 'destroy']);
        Route::put('user/{id}/reset', [UserController::class, 'reset'])->name('user.reset');
        
        //Server
        Route::resource('server', ServerController::class)->only(['index', 'store', 'edit', 'update', 'destroy']);

        //Company
        Route::resource('company', CompanyController::class)->only(['index', 'store', 'edit', 'update', 'destroy']);

        //Levels
        Route::resource('level', LevelController::class)->only(['index', 'store', 'edit', 'update', 'destroy']);

        //Log Activity
        Route::resource('log-activity', LogActivitiesController::class)->only(['index']);
    });
});
