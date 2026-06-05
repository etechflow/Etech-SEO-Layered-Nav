<?php
declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Model\Source;

use ETechFlow\SeoLayeredNav\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;

class UrlFormat implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::URL_FORMAT_QUERY, 'label' => __('Query string  (?manufacturer=yale)')],
            ['value' => Config::URL_FORMAT_PATH, 'label' => __('Path  (/category/manufacturer/yale)')],
        ];
    }
}
