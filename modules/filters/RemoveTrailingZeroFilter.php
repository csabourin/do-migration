<?php
namespace modules\filters;

use Craft;
use craft\base\Component;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class RemoveTrailingZeroFilter extends AbstractExtension
{
    public function getName(): string
    {
        return 'Remove Trailing Zero Filter';
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('removetrailingzero', [$this, 'removetrailingzero']),
        ];
    }

    /**
     * Remove triling zero 
     * 
     * @param int $value
     * @param string $language Language code ('en' or 'fr')
     * @return string Formatted vaue without trailing zero
     */
    public function RemoveTrailingZero($value = 0, string $language = 'auto'): string {
        // Detect language based on Craft's locale if not specified
        if ($language === 'auto') {
            $language = substr(Craft::$app->language, 0, 2);
            }

        // Format with two decimal places
        $formatted = number_format($value, 2, $this->getDecimalSeparator($language), $this->getThousandsSeparator($language));
        
        // Remove trailing zeros and decimal point if needed
        $formatted = rtrim(rtrim($formatted, '0'), $this->getDecimalSeparator($language));
        
        return $formatted;
    }

    
    /**
     * Get the decimal separator based on language
     */
    private function getDecimalSeparator(string $language): string
    {
        return $language === 'fr' ? ',' : '.';
    }
    
    /**
     * Get the thousands separator based on language
     */
    private function getThousandsSeparator(string $language): string
    {
        return $language === 'fr' ? ' ' : ',';
    }
}