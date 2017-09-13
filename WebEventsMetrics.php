<?php
/**
 * this class is designed to take an events file and answer a few questions
 * results our printed out as well as exported to a file given an outputFile location
 *
 * PHP 5.6
 * 
 * @author Robbin Harris <robb@robbinharris.com>
 */
class WebEventMetrics 
{
	const ANONYMOUS           = 'Anonymous';
	const AUTHENTICATED       = 'Authenticated';
	const HOME_TO_SEARCH_TIME = 1800; // 30 minutes
	protected $data;

	/**
	 * this function constructs the object and outputs our data
	 *
	 * @param string $dataFile   file location for events data
	 * @param string $outputFile output file location for our answers
	 */
	public function __construct($dataFile, $outputFile)
	{
		$this->loadFile($dataFile);
		$data = $this->getQuestionAnswers();
		echo $data;
		file_put_contents($outputFile, $data);
	}

	/**
	 * this function loads a json file 
	 *
	 * @param string $file file location
	 *
	 * @return void
	 */
	protected function loadFile($file)
	{
		// getting file contents
		$contents   = file_get_contents($file);
		// decoding data
		$this->data = json_decode($contents);
	}

	/**
	 * this function answers the questions takes the data loaded from the loadFile function
	 * and outputs the answers to the questions from the assignment.
	 *
	 * 1. How many unique users (both authenticated and anonymous) visited the homepage?
     * 2. Of authenticated users which visited the homepage, what percent go on to visit a 
     *    search page within 30 minutes?
     * 3. What is the average number of search pages that a user visits?
     * 4. Which UTM source is best at generating users which visit both a homepage and 
     *    then a search page?
     * 5. If we were testing two different versions of the homepage and trying to measure 
     *    their impact on search rates, what further information would you need and how would 
     *    you collect it?
	 *
	 * @return void
	 */
	protected function getQuestionAnswers()
	{
		// sorting the data by ts, this is important for when we process the data
		// because assumptions will be made based on the order of the pageviews
		usort($this->data, function($a, $b) {
			return $a->ts - $b->ts;
		});

		// in this first loop we will compile the events into rows, each representing
		// a unique user, authenticated or anonymous
		// if authenticated the unique user id will be the person_opk
		// if not authenticated then the distict_id for the browser session will be used
		$user_breakdown = [];
		foreach ($this->data as $event) {
			// just making sure we are only looking at pageview data
			if ($event->event_name !== 'pageview') continue;

			// here we convert the event ts to seconds to make it easyer to work with
			$event->ts /= 1000;

			// figuring out if event belongs to an authenticated user and what the unique id will be
			$user_type = self::AUTHENTICATED;
			$user_id   = $event->person_opk;
			if ($event->person_opk === null) {
				$user_type = self::ANONYMOUS;
				$user_id   = $event->distinct_id;
			}

			// creating row to represent the user if one does not exist
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

			// recording user utm source
			if (!isset($user_breakdown[$user_id]['utm'][$event->utm_source])) {
				$user_breakdown[$user_id]['utm'][$event->utm_source] = 1;
			} else {
				$user_breakdown[$user_id]['utm'][$event->utm_source]++;
			}
			// recording first utm source
			if ($user_breakdown[$user_id]['first_utm'] === null) {
				$user_breakdown[$user_id]['first_utm'] = $event->utm_source;
			}

			// latest home visit
			if ($event->page_category === 'home') {
				$user_breakdown[$user_id]['last_home_visit'] = $event->ts;
			} else if ($event->page_category === 'search'
				&& $event->ts - $user_breakdown[$user_id]['last_home_visit'] <= self::HOME_TO_SEARCH_TIME
			) {
				// here we see that the user visited the search page within HOME_TO_SEARCH_TIME seconds
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


		// outputing Question data
		ob_start();
		echo PHP_EOL;
		echo 'Question 1: '.PHP_EOL;
		foreach ($question1 as $type => $count) {
			echo "Unique {$type} Home Page Visitors: ".number_format($count, 0).PHP_EOL;
		}
		echo 'Q1 Assumptions:'.PHP_EOL;
		echo 'If authenticated person_opk was used to indicate a unique user.'.PHP_EOL;
		echo 'If anonymous distict_id was used to indicate a unique user.'.PHP_EOL;
		echo PHP_EOL;

		echo 'Question 2: Percentage of authenticated users that visit the search page within 30 mins '.PHP_EOL.
			'of viewing the home page '.(number_format(100*$question2/$question1[self::AUTHENTICATED], 2)).'%'.PHP_EOL;
		echo 'Q2 Assumptions:'.PHP_EOL;
		echo 'Same Assumptions as Q1, Data was ordered by ts in ascending order, each users '.PHP_EOL.
			'most recent home page ts was recorded, and if there was a following search page '.PHP_EOL.
			'visit with a ts <= 1800 older than the most recent home page visit it counted '.PHP_EOL.
			'towards the ratio.'.PHP_EOL;
		echo PHP_EOL;

		echo 'Question 3: Average number of pageviews per user '.number_format($question3/count($user_breakdown), 2).PHP_EOL;
		echo 'Q3 Assumptions: '.PHP_EOL;
		echo 'Same Assumptions as Q1 for definition of "User".'.PHP_EOL;
		echo 'Metric takes both Authenticated and Anonymous users.'.PHP_EOL;
		echo 'Count the total number of Search Page Views, and divide by number of unique users.'.PHP_EOL;
		echo PHP_EOL;

		echo 'Question 4: Best UTM source for generating users that visit the search page then the home page '.key($question4).PHP_EOL;
		echo 'Q4 Assumptions:'.PHP_EOL;
		echo 'First UTM Source found for a user was considered the utm that generated the user.'.PHP_EOL;
		echo 'Counted only users that first visited the home page, then visited the search page.'.PHP_EOL;
		echo 'This metric did not care about the time elapsed between homepage and search pageviews.'.PHP_EOL;
		echo PHP_EOL;

		echo 'Question 5: Information needed to calculate search rates from 2 different versions of the search page.'.PHP_EOL;
		echo 'If a search page view does not imply a user making a search, then we need event data rows '.PHP_EOL.
			'with a different event_name potentially "search". This would get logged whenever a user '.PHP_EOL.
			'performs a search request.'.PHP_EOL;
		echo 'As for the version of the search page, this could be stored in a new column '.PHP_EOL.
		'"page_category_version" and could be added to any locations that the logs are generated. '.PHP_EOL.
		'Alternatively we could alter the uri_path to reflect the page version.'.PHP_EOL.PHP_EOL;
		return ob_get_clean();
	}
}

// here we instantiate our object running our script
$runner = new WebEventMetrics(
	__DIR__.'/data/webevents.json',
	__DIR__.'/data/output.txt'
);
