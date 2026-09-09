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
            'title' => 'People',
            'body' => 'Operational People layer появится в Phase 4. Сейчас это не база людей и не Knowledge-entity person.',
        ]);
    }

    public function more(): Response
    {
        return Inertia::render('Jarvis/More');
    }

    public function meetings(): Response
    {
        return Inertia::render('Jarvis/ComingFoundation', [
            'title' => 'Meetings',
            'phase' => '5A',
            'body' => 'Встречи и ручная загрузка транскриптов появятся в Phase 5A. Автоматический Zoom — Phase 5B. Сейчас это не Meeting-объект.',
        ]);
    }

    public function commitments(): Response
    {
        return Inertia::render('Jarvis/ComingFoundation', [
            'title' => 'Commitments',
            'phase' => '6',
            'body' => 'Обязательства как отдельная сущность появятся в Phase 6. Сейчас это не Tasks и не derived list_commitments.',
        ]);
    }
}
