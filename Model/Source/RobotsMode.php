<?php
declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class RobotsMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'NOINDEX,FOLLOW', 'label' => __('NOINDEX, FOLLOW (recommended)')],
            ['value' => 'NOINDEX,NOFOLLOW', 'label' => __('NOINDEX, NOFOLLOW')],
            ['value' => 'INDEX,FOLLOW', 'label' => __('INDEX, FOLLOW')],
        ];
    }
}
