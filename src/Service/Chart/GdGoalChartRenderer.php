<?php

declare(strict_types=1);

namespace App\Service\Chart;

use App\Entity\NutritionGoal;
use App\Enum\GoalDirection;
use App\Support\Format;
use Psr\Log\LoggerInterface;

/**
 * GD implementation of GoalChartRenderer. Draws a small line chart of daily values
 * with the target (a dashed line, or a shaded band for a "maintain" range). Graphics
 * are rendered at 2× and downscaled (imagecopyresampled) so lines come out
 * anti-aliased despite GD's aliased primitives; text is drawn afterwards at native
 * size to stay crisp, using GD's built-in font (no TrueType dependency to ship).
 */
class GdGoalChartRenderer implements GoalChartRenderer
{
    private const W = 620;
    private const H = 300;
    private const PAD_L = 54;
    private const PAD_R = 18;
    private const PAD_T = 40;
    private const PAD_B = 34;
    private const SCALE = 2;
    private const WEB_BASE = '/uploads/goal-charts';

    public function __construct(
        private readonly string $chartsDir,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function render(NutritionGoal $goal, array $daily, string $stem): ?string
    {
        if ($daily === [] || !\function_exists('imagecreatetruecolor')) {
            return null;
        }

        ksort($daily);
        $values = array_values($daily);
        $dates = array_keys($daily);
        $n = count($values);

        $def = $goal->getDefinition();
        $unit = $def->unit();

        // Y range: include the target(s) so the reference is always visible.
        $bounds = array_merge($values, [$def->targetValue]);
        if ($def->targetMax !== null) {
            $bounds[] = $def->targetMax;
        }
        $minY = min($bounds);
        $maxY = max($bounds);
        if ($maxY - $minY < 1e-9) {
            $maxY += 1;
            $minY -= 1;
        }
        $span = ($maxY - $minY) * 1.1;
        $mid = ($maxY + $minY) / 2;
        $minY = $mid - $span / 2;
        $maxY = $mid + $span / 2;

        $s = self::SCALE;
        $plotL = self::PAD_L * $s;
        $plotR = (self::W - self::PAD_R) * $s;
        $plotT = self::PAD_T * $s;
        $plotB = (self::H - self::PAD_B) * $s;

        $valueToY = static fn (float $v): float => $plotB - ($v - $minY) / ($maxY - $minY) * ($plotB - $plotT);
        $indexToX = static fn (int $i): float => $n === 1
            ? ($plotL + $plotR) / 2
            : $plotL + $i / ($n - 1) * ($plotR - $plotL);

        $bw = (int) (self::W * $s);
        $bh = (int) (self::H * $s);
        $big = imagecreatetruecolor($bw, $bh);
        imagealphablending($big, true);
        imagefilledrectangle($big, 0, 0, $bw, $bh, imagecolorallocate($big, 255, 255, 255));

        $grid = imagecolorallocate($big, 233, 236, 239);
        $axis = imagecolorallocate($big, 173, 181, 189);
        $line = imagecolorallocate($big, 13, 110, 253);
        $target = imagecolorallocate($big, 25, 135, 84);
        $band = imagecolorallocatealpha($big, 25, 135, 84, 105);

        // Plot frame + a few horizontal gridlines.
        imagesetthickness($big, 1);
        for ($g = 0; $g <= 4; ++$g) {
            $y = (int) ($plotT + $g / 4 * ($plotB - $plotT));
            imageline($big, (int) $plotL, $y, (int) $plotR, $y, $grid);
        }
        imageline($big, (int) $plotL, (int) $plotT, (int) $plotL, (int) $plotB, $axis);
        imageline($big, (int) $plotL, (int) $plotB, (int) $plotR, (int) $plotB, $axis);

        // Target: a band for "maintain", otherwise a dashed line.
        if ($def->direction === GoalDirection::Maintain && $def->targetMax !== null) {
            imagefilledrectangle(
                $big,
                (int) $plotL,
                (int) $valueToY($def->targetMax),
                (int) $plotR,
                (int) $valueToY($def->targetValue),
                $band,
            );
        } else {
            imagesetthickness($big, $s);
            $dash = array_merge(array_fill(0, 6 * $s, $target), array_fill(0, 6 * $s, IMG_COLOR_TRANSPARENT));
            imagesetstyle($big, $dash);
            $ty = (int) $valueToY($def->targetValue);
            imageline($big, (int) $plotL, $ty, (int) $plotR, $ty, IMG_COLOR_STYLED);
        }

        // The value line + markers.
        imagesetthickness($big, 3 * $s);
        for ($i = 1; $i < $n; ++$i) {
            imageline(
                $big,
                (int) $indexToX($i - 1),
                (int) $valueToY($values[$i - 1]),
                (int) $indexToX($i),
                (int) $valueToY($values[$i]),
                $line,
            );
        }
        foreach ($values as $i => $v) {
            imagefilledellipse($big, (int) $indexToX($i), (int) $valueToY($v), 4 * $s, 4 * $s, $line);
        }

        // Downscale for anti-aliasing.
        $img = imagecreatetruecolor(self::W, self::H);
        imagecopyresampled($img, $big, 0, 0, 0, 0, self::W, self::H, $bw, $bh);

        $this->labels($img, $goal, $unit, $minY, $maxY, $dates);

        if (!is_dir($this->chartsDir) && !@mkdir($this->chartsDir, 0775, true) && !is_dir($this->chartsDir)) {
            $this->logger->error('Could not create goal charts dir {dir}', ['dir' => $this->chartsDir]);

            return null;
        }

        $file = $this->chartsDir . '/' . $stem . '.png';
        $ok = imagepng($img, $file);

        return $ok ? self::WEB_BASE . '/' . $stem . '.png' : null;
    }

    /**
     * Crisp text drawn on the final (native-resolution) image with GD's built-in font.
     *
     * @param \GdImage          $img
     * @param array<int|string> $dates
     */
    private function labels(
        \GdImage $img,
        NutritionGoal $goal,
        string $unit,
        float $minY,
        float $maxY,
        array $dates,
    ): void {
        $dark = imagecolorallocate($img, 33, 37, 41);
        $gray = imagecolorallocate($img, 108, 117, 125);

        $title = mb_strimwidth($goal->getName(), 0, 56, '…');
        imagestring($img, 4, self::PAD_L, 12, $title, $dark);

        imagestring($img, 3, 6, self::PAD_T - 6, Format::number($maxY) . $unit, $gray);
        imagestring($img, 3, 6, self::H - self::PAD_B - 6, Format::number($minY) . $unit, $gray);

        $first = (new \DateTimeImmutable((string) $dates[0]))->format('M j');
        $last = (new \DateTimeImmutable((string) $dates[array_key_last($dates)]))->format('M j');
        imagestring($img, 3, self::PAD_L, self::H - 20, $first, $gray);
        imagestring($img, 3, self::W - self::PAD_R - imagefontwidth(3) * strlen($last), self::H - 20, $last, $gray);
    }
}
