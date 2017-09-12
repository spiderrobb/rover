<?php
class WebEventMetrics 
{
	const ANONYMOUS           = 'Anonymous';
	const AUTHENTICATED       = 'Authenticated';
	const HOME_TO_SEARCH_TIME = 1800; // 30 minutes
	protected $data;
	public function loadFile($file)
	{
		$contents   = file_get_contents($file);
		$this->data = json_decode($contents);
		usort($this->data, function($a, $b) {
			return $a->ts - $b->ts;
		});
	}
	public function calculateMetrics()
	{
		$user_breakdown = [];
		foreach ($this->data as $event) {
			$last_ts = $event->ts;
			if ($event->event_name !== 'pageview') continue;
			$user_type = self::AUTHENTICATED;
			$user_id   = $event->person_opk;
			if ($event->person_opk === null) {
				$user_type = self::ANONYMOUS;
				$user_id   = $event->distinct_id;
			}
			if (!isset($user_breakdown[$user_id])) {
				$user_breakdown[$user_id] = [
					'id'                    => $user_id,
					'type'                  => $user_type,
					'page_view'             => [],
					'last_home_visit'       => 0,
					'visit_search_in_range' => 0,
					'home_then_search'      => 0,
					'first_utm'             => null,
					'utm'                   => [],
				];
			}
			// recording user utm
			if (!isset($user_breakdown[$user_id]['utm'][$event->utm_source])) {
				$user_breakdown[$user_id]['utm'][$event->utm_source] = 1;
			} else {
				$user_breakdown[$user_id]['utm'][$event->utm_source]++;
			}
			if ($user_breakdown[$user_id]['first_utm'] === null) {
				$user_breakdown[$user_id]['first_utm'] = $event->utm_source;
			}

			// latest home visit
			if ($event->page_category === 'home') {
				$user_breakdown[$user_id]['last_home_visit'] = $event->ts;
			} else if ($event->page_category === 'search'
				&& $event->ts - $user_breakdown[$user_id]['last_home_visit'] <= self::HOME_TO_SEARCH_TIME
			) {
				$user_breakdown[$user_id]['visit_search_in_range']++;
			}
			if ($user_breakdown[$user_id]['last_home_visit'] > 0 
				&& $event->page_category === 'search'
			) {
				$user_breakdown[$user_id]['home_then_search'] = 1;
			}

			// pageview
			if (!isset($user_breakdown[$user_id]['page_view'][$event->page_category])) {
				$user_breakdown[$user_id]['page_view'][$event->page_category] = 1;
			} else {
				$user_breakdown[$user_id]['page_view'][$event->page_category]++;
			}
		}

		// calculating homepage unique users metrics
		$question1 = [
			self::AUTHENTICATED => 0,
			self::ANONYMOUS     => 0,
		];
		$question2 = 0;
		$question3 = 0;
		$question4 = [];
		foreach ($user_breakdown as $user) {
			if (isset($user['page_view']['home'])) {
				$question1[$user['type']]++;
				if ($user['type'] === self::AUTHENTICATED 
					&& $user['visit_search_in_range'] > 0
				) {
					$question2++;
				}
			}
			if (isset($user['page_view']['search'])) {
				$question3 += $user['page_view']['search'];
			}
			if (!empty($user['first_utm'])) {
				if (!isset($question4[$user['first_utm']])) {
					$question4[$user['first_utm']] = $user['home_then_search'];
				} else {
					$question4[$user['first_utm']] += $user['home_then_search'];
				}
			}
		}
		arsort($question4);


		// outputing data
		echo PHP_EOL;
		echo 'Question 1: '.PHP_EOL;
		foreach ($question1 as $type => $count) {
			echo "Unique {$type} Home Page Visitors: {$count}".PHP_EOL;
		}
		echo 'Q1 Assumptions:'.PHP_EOL;
		echo 'If authenticated person_opk was used to indicate a unique user.'.PHP_EOL;
		echo 'If anonymous distict_id was used to indicate a unique user.'.PHP_EOL;
		echo PHP_EOL;
		echo 'Question 2: '.(100*$question2/$question1[self::AUTHENTICATED]).'%'.PHP_EOL;
		echo 'Q2 Assumptions:'.PHP_EOL;
		echo 'Same Assumptions as Q1, Data was ordered by ts in ascending order, each users '.PHP_EOL.
			'most recent home page ts was recorded, and if there was a following search page '.PHP_EOL.
			'visit with a ts <= 1800 older than the most recent home page visit it counted '.PHP_EOL.
			'towards the ratio.'.PHP_EOL;
		echo PHP_EOL;
		echo 'Question 3: '.($question3/count($user_breakdown)).PHP_EOL;
		echo 'Q3 Assumptions: '.PHP_EOL;
		echo 'Same Assumptions as Q1 for definition of "User".'.PHP_EOL;
		echo 'Metric takes both Authenticated and Anonymous users.'.PHP_EOL;
		echo 'Count the total number of Search Page Views, and divide by number of unique users.'.PHP_EOL;
		echo PHP_EOL;
		echo 'Question 4: '.key($question4).PHP_EOL;
		echo 'Q4 Assumptions:'.PHP_EOL;
		echo 'First UTM Source found for a user was considered the utm that generated the user.'.PHP_EOL;
		echo 'Counted only users that first visited the home page, then visited the search page.'.PHP_EOL;
		echo 'This metric did not care about the time elapsed between home page and search page views.'.PHP_EOL;
		echo PHP_EOL;
		echo 'Question 5: '.PHP_EOL;
		echo 'If a search page view does not imply a user making a search, then we need event data rows '.PHP_EOL.
			'with a different event_name potentially "search". This would get logged whenever a user '.PHP_EOL.
			'performs a search request.'.PHP_EOL;
		echo 'As for the version of the search page, this could be stored in a new column '.PHP_EOL.
		'"page_category_version" and could be added to any locations that the logs are generated. '.PHP_EOL.
		'Alternativly we could alter the uri_path to reflect the page version.'.PHP_EOL;
	}
}

$runner = new WebEventMetrics;
$runner->loadFile(__DIR__.'/data/webevents.json');
$runner->calculateMetrics();