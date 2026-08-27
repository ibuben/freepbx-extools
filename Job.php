<?php
namespace FreePBX\modules\Exunity;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Job implements \FreePBX\Job\TaskInterface
{
	public static function run(InputInterface $input, OutputInterface $output)
	{
		$result = \FreePBX::Exunity()->runCdrRecordingCleanup();
		if (!empty($result['skipped'])) {
			$output->writeln($result['message'] ?? 'CDR recording cleanup skipped');
			return true;
		}
		$output->writeln(sprintf(
			'CDR recording cleanup: deleted %d files, missing %d, cleared %d CDR links (keep %d days, cutoff %s)',
			(int) ($result['deleted'] ?? 0),
			(int) ($result['missing'] ?? 0),
			(int) ($result['cleared'] ?? 0),
			(int) ($result['days'] ?? 0),
			(string) ($result['cutoff'] ?? '')
		));
		return !empty($result['status']);
	}
}
