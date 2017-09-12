<?php
class WebEventMetrics 
{
	const ANONYMOUS     = 'anonymous';
	const AUTHENTICATED = 'authenticated';
	protected $data;
	public function loadFile($file)
	{
		$contents   = file_get_contents($file);
		$this->data = json_decode($contents);
	}
	public function calculateMetrics()
	{
		$page_metrics = [];
		foreach ($this->data as $event) {
			// get login status of event
			$user_type = self::AUTHENTICATED;
			$user_id   = $event->person_opk;
			if ($event->person_opk !== null) {
				$user_type = self::ANONYMOUS;
				$user_id   = $event->distinct_id;
			}
			// setup visitor metrics array if not setup already
			if (!isset($page_metrics[$event->page_category])) {
				$page_metrics[$event->page_category] = [
					self::ANONYMOUS     => [],
					self::AUTHENTICATED => [],
				];
			}
			
			// incrementing
			if (!isset($page_metrics[$event->page_category][$user_type][$user_id])) {
				$page_metrics[$event->page_category][$user_type][$user_id] = 1;
			} else {
				$page_metrics[$event->page_category][$user_type][$user_id]++;
			}
		}

		// outputing data
		foreach ($page_metrics as $page => $metrics) {
			echo "Unique Authenticated {$page} Visitors: ".count($metrics[self::AUTHENTICATED]).PHP_EOL;
			echo "Unique Anonymous {$page} Visitors: ".count($metrics[self::ANONYMOUS]).PHP_EOL;
			echo PHP_EOL;
		}
	}
}

$runner = new WebEventMetrics;
$runner->loadFile(__DIR__.'/data/webevents.json');
$runner->calculateMetrics();