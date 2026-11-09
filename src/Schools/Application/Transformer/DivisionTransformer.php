<?php

declare(strict_types=1);

namespace App\Schools\Application\Transformer;

use App\Schools\Domain\Model\Division;

/**
 * @phpstan-type DivisionData array{id: int, region: string, name: string, sdsName: ?string,
 *     sdsPosition: string, signaturePath: ?string}
 */
final class DivisionTransformer
{
    /** @return DivisionData */
    public static function toArray(Division $division): array
    {
        return [
            'id' => (int) $division->getId(),
            'region' => $division->getRegion(),
            'name' => $division->getName(),
            'sdsName' => $division->getSdsName(),
            'sdsPosition' => $division->getSdsPosition(),
            'signaturePath' => $division->getSignaturePath(),
        ];
    }
}
