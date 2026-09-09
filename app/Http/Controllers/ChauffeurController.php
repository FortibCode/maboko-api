<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ChauffeurController extends Controller
{
    public function index()
    {
        return response()->json(['message' => 'Liste des chauffeurs']);
    }

    public function show($id)
    {
        return response()->json(['message' => "Détails du chauffeur $id"]);
    }

    public function store(Request $request)
    {
        return response()->json(['message' => 'Chauffeur créé avec succès']);
    }
}
