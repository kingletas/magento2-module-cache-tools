<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Fake;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * A scope config backed by a plain array.
 */
class ArrayScopeConfig implements ScopeConfigInterface
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private array $values = [])
    {
    }

    public function getValue($path, $scopeType = self::SCOPE_TYPE_DEFAULT, $scopeCode = null)
    {
        return $this->values[$path] ?? null;
    }

    public function isSetFlag($path, $scopeType = self::SCOPE_TYPE_DEFAULT, $scopeCode = null)
    {
        $value = $this->values[$path] ?? null;

        // Mirrors Magento's own coercion, which is the point: "0" is false and
        // "1" is true, and neither is a PHP truthiness question.
        return !($value === null || $value === '' || $value === '0' || $value === 0 || $value === false);
    }
}
