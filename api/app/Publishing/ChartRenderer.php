<?php

namespace App\Publishing;

use InvalidArgumentException;

/**
 * Draws a chart from the data an author typed or pasted.
 *
 * A chart in the manuscript is its data, not a picture of it: the node keeps
 * the table (CSV or tab-separated, as Excel copies it) and the chart is
 * drawn from that table wherever it is shown. Change a number and every
 * export changes with it, and the numbers stay checkable by an examiner.
 *
 * The chart is laid out once, as a list of shapes, then drawn by either of
 * two backends: SVG for the editor, EPUB and HTML, which stay sharp at any
 * zoom; and a high-resolution PNG for the PDF and Word, whose SVG support
 * cannot be trusted with text. One layout, so the two never disagree.
 */
class ChartRenderer
{
    public const TYPES = ['bar', 'line', 'pie', 'scatter'];

    /** Okabe–Ito: distinguishable with every common colour-vision deficiency. */
    private const PALETTE = ['#0072B2', '#E69F00', '#009E73', '#D55E00', '#CC79A7', '#56B4E9', '#8C6D1F', '#000000'];

    private const WIDTH = 640;

    private const HEIGHT = 380;

    private const INK = '#1F2328';

    private const MUTED = '#5F6B7A';

    private const GRID = '#E3E7EC';

    /**
     * @param  array{type?: string, title?: string, xLabel?: string, yLabel?: string, data?: string}  $spec
     */
    public function svg(array $spec): string
    {
        $shapes = $this->layout($spec);
        $out = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.self::WIDTH.' '.self::HEIGHT.'" '
            .'width="'.self::WIDTH.'" height="'.self::HEIGHT.'" role="img" aria-label="'.e($this->describe($spec)).'" '
            .'font-family="Source Sans 3, Source Sans Pro, Helvetica, Arial, sans-serif">';

        foreach ($shapes as $s) {
            $out .= match ($s[0]) {
                'rect' => sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="%s"/>', $s[1], $s[2], $s[3], $s[4], $s[5]),
                'line' => sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="%s" stroke-width="%.1f"/>', $s[1], $s[2], $s[3], $s[4], $s[5], $s[6]),
                'polyline' => '<polyline fill="none" stroke="'.$s[2].'" stroke-width="'.$s[3].'" stroke-linejoin="round" points="'
                    .implode(' ', array_map(fn ($p) => sprintf('%.1f,%.1f', $p[0], $p[1]), $s[1])).'"/>',
                'circle' => sprintf('<circle cx="%.1f" cy="%.1f" r="%.1f" fill="%s" stroke="#FFFFFF" stroke-width="1"/>', $s[1], $s[2], $s[3], $s[4]),
                'wedge' => $this->wedgeSvg($s),
                'text' => sprintf(
                    '<text x="%.1f" y="%.1f" font-size="%.1f" text-anchor="%s" fill="%s"%s%s>%s</text>',
                    $s[1], $s[2], $s[4], $s[5], $s[7], $s[6] ? ' font-weight="600"' : '',
                    $s[8] ? sprintf(' transform="rotate(%d %.1f %.1f)"', $s[8], $s[1], $s[2]) : '',
                    e($s[3]),
                ),
                default => '',
            };
        }

        return $out.'</svg>';
    }

    /**
     * The same chart as a PNG, at $scale times the layout size.
     *
     * @param  array<string, mixed>  $spec
     */
    public function png(array $spec, int $scale = 3): string
    {
        $shapes = $this->layout($spec);
        $image = imagecreatetruecolor(self::WIDTH * $scale, self::HEIGHT * $scale);
        imageantialias($image, true);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));

        $colour = function (string $hex) use ($image) {
            [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

            return imagecolorallocate($image, $r, $g, $b);
        };

        foreach ($shapes as $s) {
            switch ($s[0]) {
                case 'rect':
                    imagefilledrectangle($image, (int) round($s[1] * $scale), (int) round($s[2] * $scale),
                        (int) round(($s[1] + $s[3]) * $scale) - 1, (int) round(($s[2] + $s[4]) * $scale) - 1, $colour($s[5]));
                    break;
                case 'line':
                    imagesetthickness($image, max(1, (int) round($s[6] * $scale)));
                    imageline($image, (int) round($s[1] * $scale), (int) round($s[2] * $scale),
                        (int) round($s[3] * $scale), (int) round($s[4] * $scale), $colour($s[5]));
                    break;
                case 'polyline':
                    imagesetthickness($image, max(1, (int) round($s[3] * $scale)));
                    for ($i = 1; $i < count($s[1]); $i++) {
                        imageline($image, (int) round($s[1][$i - 1][0] * $scale), (int) round($s[1][$i - 1][1] * $scale),
                            (int) round($s[1][$i][0] * $scale), (int) round($s[1][$i][1] * $scale), $colour($s[2]));
                    }
                    break;
                case 'circle':
                    $d = (int) round($s[3] * 2 * $scale);
                    imagefilledellipse($image, (int) round($s[1] * $scale), (int) round($s[2] * $scale), $d + 2 * $scale, $d + 2 * $scale, $colour('#FFFFFF'));
                    imagefilledellipse($image, (int) round($s[1] * $scale), (int) round($s[2] * $scale), $d, $d, $colour($s[4]));
                    break;
                case 'wedge':
                    // GD measures angles clockwise from three o'clock; the
                    // layout measures them clockwise from twelve.
                    imagefilledarc($image, (int) round($s[1] * $scale), (int) round($s[2] * $scale),
                        (int) round($s[3] * 2 * $scale), (int) round($s[3] * 2 * $scale),
                        (int) round($s[4] - 90), (int) round($s[5] - 90), $colour($s[6]), IMG_ARC_PIE);
                    break;
                case 'text':
                    $font = resource_path('fonts/'.($s[6] ? 'SourceSans3-Semibold.ttf' : 'SourceSans3-Regular.ttf'));
                    // GD sizes type in points at 96 dpi; the layout in pixels.
                    $size = $s[4] * $scale * 0.75;
                    $box = imagettfbbox($size, $s[8] ? -$s[8] : 0, $font, $s[3]);
                    $width = abs($box[2] - $box[0]);
                    $x = $s[1] * $scale;
                    $y = $s[2] * $scale;

                    if ($s[8]) {
                        // Rotated axis title: centred along its own length.
                        $height = abs($box[1] - $box[7]);
                        $y += $s[5] === 'middle' ? $height / 2 : 0;
                    } else {
                        $x -= match ($s[5]) {
                            'middle' => $width / 2,
                            'end' => $width,
                            default => 0,
                        };
                    }

                    imagettftext($image, $size, $s[8] ? -$s[8] : 0, (int) round($x), (int) round($y), $colour($s[7]), $font, $s[3]);
                    break;
            }
        }

        ob_start();
        imagepng($image, null, 6);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /**
     * The table, parsed: headers, then rows. Commas, tabs or semicolons, as
     * an author pastes from a spreadsheet.
     *
     * @return array{0: array<int, string>, 1: array<int, array<int, string>>}
     */
    public function parse(string $data): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', trim($data)) ?: []), fn ($l) => $l !== ''));

        if (count($lines) < 2) {
            throw new InvalidArgumentException('A chart needs a header row and at least one row of data.');
        }

        $delimiter = str_contains($lines[0], "\t") ? "\t" : (substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',');
        $rows = array_map(fn ($line) => array_map('trim', str_getcsv($line, $delimiter, '"', '')), $lines);

        return [array_shift($rows), $rows];
    }

    /** A sentence a screen reader can say in place of the picture. */
    public function describe(array $spec): string
    {
        $type = in_array($spec['type'] ?? 'bar', self::TYPES, true) ? $spec['type'] ?? 'bar' : 'bar';
        $title = trim((string) ($spec['title'] ?? ''));

        try {
            [$headers, $rows] = $this->parse((string) ($spec['data'] ?? ''));
        } catch (InvalidArgumentException) {
            return $title !== '' ? $title : 'Chart';
        }

        $names = array_slice($headers, 1);
        $series = count($names) > 1
            ? implode(', ', array_slice($names, 0, -1)).' and '.end($names)
            : ($names[0] ?? 'values');

        return ($title !== '' ? $title.'. ' : '').ucfirst($type).' chart of '.$series.' by '
            .($headers[0] ?? 'category').', '.count($rows).' '.(count($rows) === 1 ? 'row' : 'rows').'.';
    }

    /**
     * Shapes, in drawing order, in a 640 × 380 space.
     *
     * @return array<int, array<int, mixed>>
     */
    public function layout(array $spec): array
    {
        $type = in_array($spec['type'] ?? 'bar', self::TYPES, true) ? ($spec['type'] ?? 'bar') : 'bar';
        [$headers, $rows] = $this->parse((string) ($spec['data'] ?? ''));

        $shapes = [];
        $title = trim((string) ($spec['title'] ?? ''));
        $top = 22;

        if ($title !== '') {
            $shapes[] = ['text', self::WIDTH / 2, 24, $title, 16, 'middle', true, self::INK, 0];
            $top = 44;
        }

        $names = array_slice($headers, 1);

        if ($type === 'pie') {
            return array_merge($shapes, $this->pie($rows, $top));
        }

        // Series values, numbers only; a blank or non-number is a gap.
        $values = [];
        foreach ($names as $k => $name) {
            foreach ($rows as $i => $row) {
                $values[$k][$i] = $this->number($row[$k + 1] ?? null);
            }
        }

        $legend = count($names) > 1;
        $left = 62;
        $right = self::WIDTH - ($legend ? 150 : 24);
        $bottom = self::HEIGHT - 58;
        $plotTop = $top + 8;

        if ($type === 'scatter') {
            $xs = array_map(fn ($row) => $this->number($row[0] ?? null), $rows);
            [$xMin, $xMax, $xTicks] = $this->scale(array_filter($xs, 'is_float'), false);
        }

        $all = array_filter(array_merge(...array_values($values) ?: [[]]), 'is_float');
        [$yMin, $yMax, $yTicks] = $this->scale($all, $type === 'bar');
        $y = fn (float $v) => $bottom - ($v - $yMin) / max(1e-9, $yMax - $yMin) * ($bottom - $plotTop);

        // Gridlines and the y axis labels.
        foreach ($yTicks as $tick) {
            $shapes[] = ['line', $left, $y($tick), $right, $y($tick), self::GRID, 1];
            $shapes[] = ['text', $left - 8, $y($tick) + 4, $this->label($tick), 11, 'end', false, self::MUTED, 0];
        }

        $shapes[] = ['line', $left, $bottom, $right, $bottom, self::MUTED, 1];

        $count = max(1, count($rows));
        $slot = ($right - $left) / $count;

        if ($type === 'scatter') {
            $x = fn (float $v) => $left + ($v - $xMin) / max(1e-9, $xMax - $xMin) * ($right - $left);

            foreach ($xTicks as $tick) {
                $shapes[] = ['text', $x($tick), $bottom + 18, $this->label($tick), 11, 'middle', false, self::MUTED, 0];
            }

            foreach ($values as $k => $series) {
                foreach ($series as $i => $v) {
                    if (is_float($v) && is_float($xs[$i])) {
                        $shapes[] = ['circle', $x($xs[$i]), $y($v), 4, self::PALETTE[$k % 8]];
                    }
                }
            }
        } else {
            // Category labels, thinned out when there are too many to read.
            $every = (int) ceil($count / 12);
            foreach ($rows as $i => $row) {
                if ($i % $every === 0) {
                    $shapes[] = ['text', $left + $slot * ($i + 0.5), $bottom + 18, $this->shorten((string) ($row[0] ?? ''), $slot * $every), 11, 'middle', false, self::MUTED, 0];
                }
            }

            if ($type === 'bar') {
                $group = $slot * 0.72;
                $bar = $group / max(1, count($names));
                $zero = $y(max($yMin, min(0.0, $yMax)));

                foreach ($values as $k => $series) {
                    foreach ($series as $i => $v) {
                        if (! is_float($v)) {
                            continue;
                        }

                        $x0 = $left + $slot * $i + ($slot - $group) / 2 + $bar * $k;
                        $shapes[] = ['rect', $x0 + 0.5, min($y($v), $zero), max(1, $bar - 1), abs($zero - $y($v)), self::PALETTE[$k % 8]];
                    }
                }
            } else {
                foreach ($values as $k => $series) {
                    $points = [];
                    foreach ($series as $i => $v) {
                        if (is_float($v)) {
                            $points[] = [$left + $slot * ($i + 0.5), $y($v)];
                        }
                    }

                    $shapes[] = ['polyline', $points, self::PALETTE[$k % 8], 2.5];

                    foreach ($points as [$px, $py]) {
                        $shapes[] = ['circle', $px, $py, 3.5, self::PALETTE[$k % 8]];
                    }
                }
            }
        }

        // Axis titles.
        if ($xLabel = trim((string) ($spec['xLabel'] ?? ($type === 'scatter' ? '' : $headers[0] ?? '')))) {
            $shapes[] = ['text', ($left + $right) / 2, self::HEIGHT - 16, $xLabel, 12, 'middle', false, self::INK, 0];
        }

        if ($yLabel = trim((string) ($spec['yLabel'] ?? ''))) {
            $shapes[] = ['text', 18, ($plotTop + $bottom) / 2, $yLabel, 12, 'middle', false, self::INK, -90];
        }

        if ($legend) {
            foreach ($names as $k => $name) {
                $ly = $plotTop + 8 + $k * 22;
                $shapes[] = ['rect', $right + 18, $ly - 9, 12, 12, self::PALETTE[$k % 8]];
                $shapes[] = ['text', $right + 36, $ly + 1, $this->shorten($name, 100), 12, 'start', false, self::INK, 0];
            }
        }

        return $shapes;
    }

    /** @param array<int, array<int, string>> $rows */
    private function pie(array $rows, float $top): array
    {
        $values = array_map(fn ($row) => max(0.0, (float) $this->number($row[1] ?? null)), $rows);
        $total = array_sum($values);

        if ($total <= 0) {
            throw new InvalidArgumentException('A pie chart needs positive numbers in its second column.');
        }

        $shapes = [];
        $r = min(130, (self::HEIGHT - $top - 30) / 2);
        $cx = 210;
        $cy = $top + 10 + $r;
        $angle = 0.0;

        foreach ($values as $i => $v) {
            $sweep = $v / $total * 360;
            $shapes[] = ['wedge', $cx, $cy, $r, $angle, $angle + $sweep, self::PALETTE[$i % 8]];
            $angle += $sweep;
        }

        foreach ($rows as $i => $row) {
            $ly = $top + 24 + $i * 24;
            $share = round($values[$i] / $total * 100, 1);
            $shapes[] = ['rect', 390, $ly - 10, 13, 13, self::PALETTE[$i % 8]];
            $shapes[] = ['text', 410, $ly + 1, $this->shorten((string) ($row[0] ?? ''), 160).' ('.$this->label($share).'%)', 12.5, 'start', false, self::INK, 0];
        }

        return $shapes;
    }

    private function wedgeSvg(array $s): string
    {
        [, $cx, $cy, $r, $start, $end, $fill] = $s;

        if ($end - $start >= 359.99) {
            return sprintf('<circle cx="%.1f" cy="%.1f" r="%.1f" fill="%s"/>', $cx, $cy, $r, $fill);
        }

        $point = fn (float $deg) => [$cx + $r * sin(deg2rad($deg)), $cy - $r * cos(deg2rad($deg))];
        [$x1, $y1] = $point($start);
        [$x2, $y2] = $point($end);

        return sprintf('<path d="M%.1f,%.1f L%.1f,%.1f A%.1f,%.1f 0 %d 1 %.1f,%.1f Z" fill="%s" stroke="#FFFFFF" stroke-width="1.5"/>',
            $cx, $cy, $x1, $y1, $r, $r, $end - $start > 180 ? 1 : 0, $x2, $y2, $fill);
    }

    /**
     * Rounded axis bounds and ticks: 0, 5, 10, not 0, 4.37, 8.74.
     *
     * @param  array<int, float>  $values
     * @return array{0: float, 1: float, 2: array<int, float>}
     */
    private function scale(array $values, bool $fromZero): array
    {
        $min = $values ? min($values) : 0.0;
        $max = $values ? max($values) : 1.0;

        if ($fromZero) {
            $min = min(0.0, $min);
            $max = max(0.0, $max);
        }

        if ($max - $min < 1e-9) {
            $max = $min + 1;
        }

        $raw = ($max - $min) / 5;
        $magnitude = 10 ** floor(log10($raw));
        $step = collect([1, 2, 2.5, 5, 10])->map(fn ($m) => $m * $magnitude)->first(fn ($s) => $s >= $raw) ?? $magnitude * 10;
        $low = floor($min / $step) * $step;
        $high = ceil($max / $step) * $step;

        $ticks = [];
        for ($t = $low; $t <= $high + $step / 2; $t += $step) {
            $ticks[] = round($t, 10);
        }

        return [$low, $high, $ticks];
    }

    private function number(mixed $value): ?float
    {
        $text = str_replace([',', '%', ' ', "\u{00A0}"], '', trim((string) $value));

        return is_numeric($text) ? (float) $text : null;
    }

    private function label(float $value): string
    {
        $text = abs($value) >= 1000 ? number_format($value, 0) : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }

    private function shorten(string $text, float $room): string
    {
        $fits = max(3, (int) floor($room / 6.2));

        return mb_strlen($text) > $fits ? mb_substr($text, 0, $fits - 1).'…' : $text;
    }
}
