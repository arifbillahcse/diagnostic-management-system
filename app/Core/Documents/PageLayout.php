<?php

declare(strict_types=1);

namespace App\Core\Documents;

use App\Core\Settings\Settings;

/**
 * The page set-up shared by every printed report (lab, imaging): plain
 * paper with the clinic header or banner images, or pre-printed letterhead
 * paper with fixed margins, a footer line, and an optional watermark.
 */
final class PageLayout
{
    /** "print" draws the clinic header; "preprinted" leaves space for pre-printed paper. */
    public const SETTING_LETTERHEAD = 'documents.letterhead';

    public const SETTING_TOP_MARGIN = 'documents.top_margin_mm';

    public const SETTING_BOTTOM_MARGIN = 'documents.bottom_margin_mm';

    public const SETTING_FOOTER = 'documents.footer_text';

    /** Full-width banner images for the top and bottom of every page (plain paper only). */
    public const SETTING_HEADER_IMAGE = 'documents.header_image_path';

    public const SETTING_FOOTER_IMAGE = 'documents.footer_image_path';

    /** Faint copy of the logo behind the content. */
    public const SETTING_WATERMARK = 'documents.watermark';

    /** Banner file size limits, in KB. They keep PDF creation fast. */
    public const HEADER_IMAGE_MAX_KB = 150;

    public const FOOTER_IMAGE_MAX_KB = 60;

    public function __construct(
        private readonly Settings $settings,
        private readonly ClinicFiles $files,
    ) {}

    public function preprinted(): bool
    {
        return $this->settings->get(self::SETTING_LETTERHEAD, 'print') === 'preprinted';
    }

    /** @return array{preprinted: bool, header_image: ?array, footer_image: ?array, watermark: ?string, top_mm: int, bottom_mm: int, footer: ?string} */
    public function data(): array
    {
        $preprinted = $this->preprinted();
        $header = $preprinted ? null : $this->banner(self::SETTING_HEADER_IMAGE, 70);
        $footer = $preprinted ? null : $this->banner(self::SETTING_FOOTER_IMAGE, 40);
        $logo = $this->settings->get(self::SETTING_WATERMARK, false) && ! $preprinted
            ? $this->files->dataUri($this->settings->get('clinic.logo_path'))
            : null;

        return [
            'preprinted' => $preprinted,
            'header_image' => $header,
            'footer_image' => $footer,
            'watermark' => $logo,
            // Margins leave room for the banners, which are printed edge to edge.
            'top_mm' => $preprinted ? (int) $this->settings->get(self::SETTING_TOP_MARGIN, 40) : ($header ? (int) ceil($header['height_mm']) + 4 : 12),
            'bottom_mm' => $preprinted ? (int) $this->settings->get(self::SETTING_BOTTOM_MARGIN, 25) : ($footer ? (int) ceil($footer['height_mm']) + 8 : 14),
            'footer' => $this->settings->get(self::SETTING_FOOTER),
        ];
    }

    /**
     * A banner stretched across an A4 page (210 mm). One taller than the
     * limit is shown at the limit's height instead, keeping its proportions.
     *
     * @return array{uri: string, width_mm: float, height_mm: float}|null
     */
    private function banner(string $setting, int $maxHeightMm): ?array
    {
        $uri = $this->files->dataUri($this->settings->get($setting));
        $size = $uri === null ? false : @getimagesizefromstring((string) base64_decode(substr($uri, strpos($uri, ',') + 1), true));

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return null;
        }

        $height = 210 * $size[1] / $size[0];

        if ($height > $maxHeightMm) {
            return ['uri' => $uri, 'width_mm' => round($maxHeightMm * $size[0] / $size[1], 1), 'height_mm' => (float) $maxHeightMm];
        }

        return ['uri' => $uri, 'width_mm' => 210.0, 'height_mm' => round($height, 1)];
    }
}
