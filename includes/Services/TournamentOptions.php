<?php
declare(strict_types=1);

namespace PlatzStatus\Services;

final class TournamentOptions
{
    private const OPTION_IMPACT_PT = 'platzstatus_impact_post_type';

    public const META_IS_TOURNAMENT = 'ps_is_tournament';
    public const META_HOLES = 'ps_tournament_holes'; // 9|18
    public const META_FORMAT = 'ps_tournament_format';
    public const META_ENABLE_SCORECARDS = 'ps_tournament_enable_scorecards';
    public const META_ENABLE_IMPORT = 'ps_tournament_enable_import';

    public static function impactPostType(): string
    {
        return (string) get_option(self::OPTION_IMPACT_PT, 'ereignis');
    }

    public static function isTournament(int $postId): bool
    {
        return (int) get_post_meta($postId, self::META_IS_TOURNAMENT, true) === 1;
    }

    public static function holes(int $postId): int
    {
        $v = (int) get_post_meta($postId, self::META_HOLES, true);
        return ($v === 9) ? 9 : 18;
    }
}
