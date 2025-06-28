<?php

namespace App\Service;

class QueryProcessor
{
    private const REVIEW_CODE_MAPPINGS = [
        'F' => 'Favorable',
        'S' => 'Suspendu',
        'D' => 'Défavorable',
        'PM' => 'Pour mémoire',
        'SO' => 'Sans objet',
        'HM' => 'Hors mission',
        'C' => 'Conforme',
        'NC' => 'Non conforme'
    ];

    public function normalizeQuestion(string $question): string
    {
        // 1. Normaliser les codes d'avis
        $question = $this->normalizeReviewCodes($question);

        // 2. Autres normalisations possibles
        $question = $this->normalizeCommonAbbreviations($question);

        return $question;
    }

    private function normalizeReviewCodes(string $question): string
    {
        // Pattern pour "avis X" où X est un code
        $pattern = '/\bavis\s+([A-Z]{1,2})\b/i';

        return preg_replace_callback($pattern, function($matches) {
            $code = strtoupper($matches[1]);
            $fullName = self::REVIEW_CODE_MAPPINGS[$code] ?? $code;
            return "avis {$fullName}";
        }, $question);
    }

    private function normalizeCommonAbbreviations(string $question): string
    {
        $abbreviations = [
            '/\binfos?\b/i' => 'informations',
            '/\bdonnés?\s+des\s+infos?\b/i' => 'donner des informations',
            '/\bréf\.?\s/i' => 'référence ',
        ];

        foreach ($abbreviations as $pattern => $replacement) {
            $question = preg_replace($pattern, $replacement, $question);
        }

        return $question;
    }
}