<?php

declare(strict_types=1);

namespace Varsite\Platform\Contracts;

/**
 * Moduł platformy.
 *
 * Jedna metoda: moduł przedstawia swoją tożsamość. Wszystko, co platforma
 * powinna o nim wiedzieć, mieści się w manifeście — przyszłe metadane dochodzą
 * jako jego pola, nie jako kolejne interfejsy.
 *
 * Rejestracja tras, polityk i możliwości odbywa się przez ModuleManager::module(),
 * który wykonuje ją wyłącznie dla modułu aktywnego. Provider nie zawiera warunków.
 */
interface PlatformModule
{
    public function manifest(): ModuleManifest;
}
