<?php
namespace FreePBX\modules\Exunity;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StatsJob implements \FreePBX\Job\TaskInterface
{
	public static function run(InputInterface $input, OutputInterface $output)
	{
		$result = \FreePBX::Exunity()->maybeSendUsageStats();
		$output->writeln($result['message'] ?? 'Usage stats skipped');
		return true;
	}
}
