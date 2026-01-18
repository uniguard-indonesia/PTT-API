<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\User;

class ProfileController extends Controller
{
    public function index()
    {
        $data = [
            'title' => 'Profile | PTT UniGuard',
            'user' => Auth::user()
        ];
        return view('pages.profile', $data);
    }

    public function update(Request $request)
    {
        $user = Auth::user();
        
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:1024',
            'current_password' => 'nullable|required_with:new_password',
            'new_password' => 'nullable|min:6|required_with:current_password|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'fields' => $validator->errors(),
            ], 400);
        }

        try {
            // Update name and email
            $user->name = $request->name;
            $user->email = $request->email;

            // Update photo if provided
            if ($request->hasFile('photo')) {
                $file = $request->file('photo');
                $fileName = time() . '_' . str_replace(' ', '_', $file->getClientOriginalName());
                $file->move(public_path('dist/profiles/'), $fileName);
                
                // Delete old photo if exists
                if ($user->photo && file_exists(public_path('dist/profiles/' . $user->photo))) {
                    unlink(public_path('dist/profiles/' . $user->photo));
                }
                
                $user->photo = $fileName;
            }

            // Update password if provided
            if ($request->filled('current_password')) {
                if (!Hash::check($request->current_password, $user->password)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Current password is incorrect!',
                    ], 400);
                }
                $user->password = Hash::make($request->new_password);
            }

            $user->save();

            $this->saveActivity('Update Profile', $user->id);

            return response()->json([
                'status' => 'success',
                'message' => 'Profile updated successfully!',
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update profile: ' . $e->getMessage(),
            ], 500);
        }
    }
}
