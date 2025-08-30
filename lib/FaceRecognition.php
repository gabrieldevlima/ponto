<?php
/**
 * Face Recognition Library
 * Handles comparison of face descriptors for attendance validation
 */

class FaceRecognition
{
    /**
     * Compare a face descriptor against stored descriptors for a teacher
     * 
     * @param array $probeDescriptor Single face descriptor from check-in
     * @param array $teacherDescriptors Array of stored face descriptors
     * @param float $threshold Minimum similarity threshold (default 0.6)
     * @return array Result with ['match' => bool, 'score' => float, 'best_match_idx' => int]
     */
    public static function compareSingleDescriptor(array $probeDescriptor, array $teacherDescriptors, float $threshold = 0.6): array
    {
        if (empty($probeDescriptor) || empty($teacherDescriptors)) {
            return ['match' => false, 'score' => 0.0, 'best_match_idx' => -1];
        }

        $bestScore = 0.0;
        $bestIdx = -1;

        foreach ($teacherDescriptors as $idx => $storedDescriptor) {
            if (!is_array($storedDescriptor) || count($storedDescriptor) !== count($probeDescriptor)) {
                continue;
            }

            $similarity = self::calculateCosineSimilarity($probeDescriptor, $storedDescriptor);
            if ($similarity > $bestScore) {
                $bestScore = $similarity;
                $bestIdx = $idx;
            }
        }

        return [
            'match' => $bestScore >= $threshold,
            'score' => $bestScore,
            'best_match_idx' => $bestIdx
        ];
    }

    /**
     * Compare multiple face descriptors against stored descriptors
     * Returns the best match among all combinations
     * 
     * @param array $probeDescriptors Array of face descriptors from check-in
     * @param array $teacherDescriptors Array of stored face descriptors
     * @param float $threshold Minimum similarity threshold (default 0.6)
     * @return array Result with ['match' => bool, 'score' => float, 'probe_idx' => int, 'stored_idx' => int]
     */
    public static function compareMultipleDescriptors(array $probeDescriptors, array $teacherDescriptors, float $threshold = 0.6): array
    {
        if (empty($probeDescriptors) || empty($teacherDescriptors)) {
            return ['match' => false, 'score' => 0.0, 'probe_idx' => -1, 'stored_idx' => -1];
        }

        $bestScore = 0.0;
        $bestProbeIdx = -1;
        $bestStoredIdx = -1;

        foreach ($probeDescriptors as $probeIdx => $probeDescriptor) {
            if (!is_array($probeDescriptor)) continue;

            $result = self::compareSingleDescriptor($probeDescriptor, $teacherDescriptors, $threshold);
            
            if ($result['score'] > $bestScore) {
                $bestScore = $result['score'];
                $bestProbeIdx = $probeIdx;
                $bestStoredIdx = $result['best_match_idx'];
            }
        }

        return [
            'match' => $bestScore >= $threshold,
            'score' => $bestScore,
            'probe_idx' => $bestProbeIdx,
            'stored_idx' => $bestStoredIdx
        ];
    }

    /**
     * Calculate cosine similarity between two face descriptors
     * 
     * @param array $a First descriptor vector
     * @param array $b Second descriptor vector
     * @return float Similarity score (0.0 to 1.0)
     */
    private static function calculateCosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b) || empty($a)) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < count($a); $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $normA = sqrt($normA);
        $normB = sqrt($normB);

        if ($normA == 0.0 || $normB == 0.0) {
            return 0.0;
        }

        return $dotProduct / ($normA * $normB);
    }

    /**
     * Get validation notes for audit purposes
     * 
     * @param array $faceResults Results from face comparison
     * @param int $descriptorCount Number of descriptors provided
     * @return array Validation notes
     */
    public static function getValidationNotes(array $faceResults, int $descriptorCount): array
    {
        return [
            'face' => [
                'probes' => $descriptorCount,
                'best_score' => $faceResults['score'] ?? 0.0,
                'match' => $faceResults['match'] ?? false,
                'probe_idx' => $faceResults['probe_idx'] ?? $faceResults['best_match_idx'] ?? -1,
                'stored_idx' => $faceResults['stored_idx'] ?? $faceResults['best_match_idx'] ?? -1
            ]
        ];
    }
}