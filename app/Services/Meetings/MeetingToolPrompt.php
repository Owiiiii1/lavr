<?php

namespace App\Services\Meetings;

final class MeetingToolPrompt
{
    /**
     * @return list<string>
     */
    public static function toolNames(): array
    {
        return [
            'list_meetings',
            'find_meeting',
            'get_meeting',
            'get_meeting_analysis',
        ];
    }

    /**
     * @return list<string>
     */
    public static function lines(): array
    {
        return [
            'Meetings are first-class operational records. Calendar events and Knowledge events are not Meetings.',
            'list_meetings / find_meeting / get_meeting / get_meeting_analysis read Meeting Intelligence. Use them for “що вирішили”, “хто був”, “які дедлайни”, “ризики”, “що пообіцяв”.',
            'Answers must come from stored analysis JSON. commitments_detected are analysis results, not first-class commitments. Do not invent decisions or owners.',
            'If analysis_status is pending or processing, say the transcript is still being analyzed. If failed, say analysis failed and quote error_message only.',
            'Never create People, Projects, or commitments from meeting tools. Never dump the full transcript unless the user asked for the transcript.',
        ];
    }
}
