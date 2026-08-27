<?php

namespace PhoneManager;

class PhoneManager
{
    /** @var array<string, PhoneDriverInterface> */
    private array $drivers = [];

    public function __construct(array $config = [])
    {
        $this->registerDriver(new FanvilDriver($config));
        $this->registerDriver(new GrandstreamDriver($config));
        $this->registerDriver(new YealinkDriver($config));
        $this->registerDriver(new MicroSIPDriver($config));
    }

    public function registerDriver(PhoneDriverInterface $driver): void
    {
        $this->drivers[$driver->getCode()] = $driver;
    }

    public function getDriver(string $code): ?PhoneDriverInterface
    {
        return $this->drivers[$code] ?? null;
    }

    /** @return PhoneDriverInterface[] */
    public function getDrivers(): array
    {
        return $this->drivers;
    }

    public function detectVendor(string $ip, string $user = 'admin', string $pass = 'admin'): ?PhoneDriverInterface
    {
        foreach ($this->drivers as $driver) {
            if ($driver->detect($ip, $user, $pass)) {
                return $driver;
            }
        }
        return null;
    }

    public function ping(string $ip): bool
    {
        $driver = reset($this->drivers);
        return $driver ? $driver->ping($ip) : false;
    }

    public function getInfo(string $ip, ?string $vendorCode = null, string $user = 'admin', string $pass = 'admin'): array
    {
        if ($vendorCode && isset($this->drivers[$vendorCode])) {
            return $this->drivers[$vendorCode]->getInfo($ip, $user, $pass);
        }
        $driver = $this->detectVendor($ip, $user, $pass);
        if (!$driver) {
            return ['vendor' => null, 'model' => null, 'mac' => null, 'firmware' => null];
        }
        return $driver->getInfo($ip, $user, $pass);
    }

    public function provision(string $ip, array $config, ?string $vendorCode = null, string $user = 'admin', string $pass = 'admin'): array
    {
        $driver = null;
        if ($vendorCode && isset($this->drivers[$vendorCode])) {
            $driver = $this->drivers[$vendorCode];
        } else {
            $driver = $this->detectVendor($ip, $user, $pass);
        }
        if (!$driver) {
            return ['success' => false, 'message' => 'Не удалось определить производителя телефона'];
        }
        return $driver->provision($ip, $config, $user, $pass);
    }
}
