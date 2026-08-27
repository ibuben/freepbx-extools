<?php

namespace PhoneManager;

interface PhoneDriverInterface
{
    public function getCode(): string;

    public function getName(): string;

    /** Проверка доступности телефона по IP */
    public function ping(string $ip): bool;

    /** Определение, является ли устройство телефоном данного вендора */
    public function detect(string $ip, string $user = 'admin', string $pass = 'admin'): bool;

    /** Получение информации об устройстве */
    public function getInfo(string $ip, string $user = 'admin', string $pass = 'admin'): array;

    /** Применение конфигурации к телефону */
    public function provision(string $ip, array $config, string $user = 'admin', string $pass = 'admin'): array;

    /** Генерация файла автопровиженинга (.cfg / .xml) */
    public function generateCfg(array $config): string;

    public function cfgContentType(): string;
}
