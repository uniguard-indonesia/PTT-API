<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ServerController extends Controller
{
    public function index()
    {
        $server = Auth::user()->load('servers.server');
        $uniqueServers = $server->servers->unique('server_id')->values();
        return response()->json([
            'success' => true,
            'message' => 'Server list successfully fetched',
            'data' => $uniqueServers
        ]);
    }

    public function show($id)
    {
        $server = Auth::user()->servers()->find($id);
        if (!$server) {
            return response()->json(['message' => 'Server not found'], 404);
        }
        return response()->json($server);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'host' => 'required|string|max:255',
            'port' => 'required|integer',
        ]);

        $server = new Server($request->all());
        $server->username = Auth::user()->name;
        $server->password = "";
        $server->user_id = Auth::id();
        $server->save();

        return response()->json(['message' => 'Server created successfully', 'server' => $server], 201);
    }

    public function update(Request $request, $id)
    {
        $server = Auth::user()->servers()->find($id);
        if (!$server) {
            return response()->json(['message' => 'Server not found'], 404);
        }

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'host' => 'sometimes|required|string|max:255',
            'port' => 'sometimes|required|integer',
            'username' => 'sometimes|string|max:255',
            'password' => 'sometimes|string|max:255',
        ]);

        $server->update($request->all());

        return response()->json(['message' => 'Server updated successfully', 'server' => $server]);
    }

    public function destroy($id)
    {
        $server = Auth::user()->servers()->find($id);
        if (!$server) {
            return response()->json(['message' => 'Server not found'], 404);
        }

        $server->delete();

        return response()->json(['message' => 'Server deleted successfully']);
    }
}
