<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class MissionController extends Controller
{
    public function index()
    {
        return response()->json(['message' => 'Liste des missions']);
    }

    public function show($id)
    {
        return response()->json(['message' => "Détails de la mission $id"]);
    }

    public function store(Request $request)
    {
        return response()->json(['message' => 'Mission créée avec succès']);
    }
}
