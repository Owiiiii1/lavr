<?php

namespace App\Services\OwnerContext;

final class OwnerContextExtractionPrompt
{
    public function system(): string
    {
        return implode("\n", [
            'Extract atomic owner-context claims from the source. Return JSON only: {"items":[...]}.',
            'Each item is one claim, rule, or context fact. Never a paragraph.',
            'Fields: value, category, fact_class, scope_type, scope_label, sensitivity, confidence, evidence_excerpt.',
            'fact_class is fact, current, historical, analysis, or to_verify.',
            'Do not upgrade to_verify to fact. Do not convert analysis into fact.',
            'Do not invent dates, roles, health, or psychology. Do not write personality labels.',
            'category is one of: identity, communication, ceo_goal, ceo_operating_rule, ceo_development, business_context, business_rule, priority, role_context, personal_constraint, other.',
            'scope_type is owner, business, organization, project, or person. scope_label is the name when needed.',
            'sensitivity is normal, private, or restricted. Restricted is for health, family, or intimate detail.',
            'confidence is 0 to 1. evidence_excerpt is a short quote, at most 180 characters.',
            'Skip filler, duplicate prose, motivational lines, and sensitive detail with no operational use.',
            'Prefer operationally useful items.',
        ]);
    }

    public function user(string $chunk): string
    {
        return "Source chunk:\n".$chunk;
    }
}
