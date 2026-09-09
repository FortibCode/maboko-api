<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Rend $this->authorize() disponible : Laravel 11 ne l'inclut plus par defaut.
    use AuthorizesRequests;
}
