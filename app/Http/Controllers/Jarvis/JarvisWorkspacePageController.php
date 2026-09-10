<?php

namespace App\Http\Controllers\Jarvis;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class JarvisWorkspacePageController extends Controller
{
    public function people(): Response
    {
        return Inertia::render('Jarvis/People', [
            'phase' => '4',
        ]);
    }

    public function more(): Response
    {
        return Inertia::render('Jarvis/More');
    }
}
