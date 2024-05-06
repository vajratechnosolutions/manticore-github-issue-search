<?php declare(strict_types=1);

namespace App\Lib;

use App\Model\Comment;
use App\Model\Issue;
use App\Model\Notification;
use App\Model\Org;
use App\Model\Repo;
use App\Model\User;
use Generator;
use Manticoresearch\Client;
use Manticoresearch\Query\BoolQuery;
use Manticoresearch\Query\KnnQuery;
use Manticoresearch\Query\QueryString;
use Manticoresearch\ResultHit;
use Manticoresearch\Search;
use ReflectionClass;
use Result;
use Throwable;

class Manticore {
	const PERSISTENCE_FACTOR = 0.8;

	const HIGHLIGHT_CONFIG = [
		// 'html_strip_mode' => 'strip',
		'pre_tags' => '<span>',
		'post_tags' => '</span>',
	];

	/**
	 * Get current instance of the client
	 * @return Client
	 */
	protected static function client(): Client {
		static $client;

		if (!$client) {
			$client = new Client(config('manticore'));
		}

		return $client;
	}

	/**
	 * Add any type of model to the index on permanent store
	 * @param array<Issue|User|Comment> $list
	 * @return Result
	 */
	public static function add(array $list): Result {
		if (!$list) {
			return ok();
		}

		$table = 'doc';
		try {
			$reflection = new ReflectionClass($list[0]);
			$client = static::client();
			$table = strtolower($reflection->getShortName());
			$index = $client->index($table);
			$docs = array_map(fn ($v) => (array)$v, $list);
			$index->replaceDocuments($docs);
			return ok();
		} catch (Throwable) {
			return err("e_add_{$table}_failed");
		}
	}


	/**
	 * Get repo by org and name
	 * @param string $org
	 * @return Result<Org>
	 */
	public static function findOrg(string $org): Result {
		$client = static::client();
		$index = $client->index('org');
		$result = $index->search('')->filter('name', $org)->get();
		$doc = iterator_to_array($result)[0] ?? null;
		if (!$doc) {
			return err('e_org_not_found');
		}
		$org = new Org(
			array_merge(
				$doc->getData(), [
				'id' => (int)$doc->getId(),
				]
			)
		);
		return ok($org);
	}

	/**
	 * Get repo by org and name
	 * @param int $org_id
	 * @param string $name
	 * @return Result<Repo>
	 */
	public static function findRepo(int $org_id, string $name): Result {
		$client = static::client();
		$index = $client->index('repo');
		$result = $index->search('')->filter('org_id', $org_id)->filter('name', $name)->get();
		$doc = iterator_to_array($result)[0] ?? null;
		if (!$doc) {
			return err('e_repo_not_found');
		}
		$repo = new Repo(
			array_merge(
				$doc->getData(), [
				'id' => (int)$doc->getId(),
				]
			)
		);
		return ok($repo);
	}


	/**
	 * Find id by org
	 * @param  string $org
	 * @param array<string,mixed> $data
	 * @return Result<int>
	 */
	public static function findOrCreateOrg(string $org, array $data = []): Result {
		$orgResult = static::findOrg($org);
		if (!$orgResult->err) {
			return $orgResult;
		}

		$data = array_replace(
			[
			'name' => $org,
			'public_repos' => 0,
			'followers' => 0,
			'following' => 0,
			'updated_at' => 0,
			], $data
		);
		$org = new Org($data);
		$result = static::add([$org]);
		if ($result->err) {
			return $result;
		}
		return ok($org);
	}

	/**
	 * Find repo id by organization and name
	 * @param  int $org_id
	 * @param  string $name
	 * @param array<string,mixed> $data
	 * @return Result<int>
	 */
	public static function findOrCreateRepo(int $org_id, string $name, array $data = []): Result {
		$repoResult = static::findRepo($org_id, $name);
		if (!$repoResult->err) {
			return $repoResult;
		}

		$data = array_replace(
			[
			'org_id' => $org_id,
			'name' => $name,
			'is_indexing' => true,
			'expected_issues' => 0,
			'issues' => 0,
			'pull_requests' => 0,
			'comments' => 0,
			'updated_at' => 0,
			], $data
		);
		$repo = new Repo($data);
		$result = static::add([$repo]);
		if ($result->err) {
			return $result;
		}
		return ok($repo);
	}

	/**
	 * Perform the multimodel search and return combined list
	 * @param  string $query
	 * @param array<string,mixed> $filters
	 * @param string $sort One of: best-match, most-commented,
	 *  least-commented, newest, oldest, recently-updated,
	 *  least-recently-updated, most-reacted,
	 *  most-positive-reacted, most-negative-reacted
	 * @param int $offset
	 * @return Result<array{time:int,list:array<Issue>}>
	 */
	public static function search(string $query = '', array $filters = [], string $sort = 'best-match', int $offset = 0): Result {
		$search_issues = $filters['issues'] ?? true;
		$search_pull_requests = $filters['pull_requests'] ?? true;
		$search_comments = $filters['comments'] ?? true;
		$time = 0;

		// Collect user_ids for appending to resulting by using single query to the manticore
		$user_ids = [];
		$label_ids = [];
		$repo_ids = [];

		$issue_count = 0;
		$issue_relation = 'eq';
		$pull_request_count = 0;
		$items = [];
		if ($search_issues || $search_pull_requests) {
			$search = static::getSearch('issue', $query, $filters)
				->offset($offset)
				->highlight(
					['title', 'body'],
					static::HIGHLIGHT_CONFIG
				);
			;
			static::applyConfig($search);

			// Apply varios filters on search instance
			static::applyFilters($search, $filters, 'issues');

			// Apply sorting
			static::applySorting($search, $sort);
			$docs = $search->get();
			$time += (int)($docs->getResponse()->getTime() * 1000);
			// Confused ?
			$issue_relation = $docs->getResponse()->getResponse()['hits']['total_relation'] ?? 'eq';
			if ($search_pull_requests) {
				$pull_request_count = $docs->getTotal();
			} else {
				$issue_count = $docs->getTotal();
			}

			foreach ($docs as $n => $doc) {
				$row = ['id' => (int)$doc->getId(), ...$doc->getData()];
				$row['highlight'] = static::highlight($doc, strip_tags($row['body']));
				$repo_ids[] = $row['repo_id'];
				$user_ids[] = $row['user_id'];
				if ($row['label_ids']) {
					$label_ids = array_merge($label_ids, $row['label_ids']);
				}
				$row['is_closed'] = $row['closed_at'] > 0;
				$row['is_open'] = !$row['is_closed'];
				$rbp_score = pow(static::PERSISTENCE_FACTOR, $n) * $doc->getScore();
				$items[] = ['score' => $rbp_score, 'issue' => $row, 'comment' => []];
			}
		}

		$comment_count = 0;
		$comment_relation = 'eq';
		if ($search_comments) {
			$search = static::getSearch('comment', $query, $filters)
				->offset($offset)
				->highlight(
					['body'],
					static::HIGHLIGHT_CONFIG
				);
			static::applyConfig($search);
			static::applyFilters($search, $filters, 'comments');
			// We can sort comments by all but comments
			if ($sort !== 'most-commented' && $sort !== 'least-commented') {
				static::applySorting($search, $sort);
			}

			// Collect issue ids to append it by adding only one extra query to manticore
			$issue_ids = [];
			$docs = $search->get();
			$time += (int)($docs->getResponse()->getTime() * 1000);
			$comment_relation = $docs->getResponse()->getResponse()['hits']['total_relation'] ?? 'eq';
			$comment_count = $docs->getTotal();
			foreach ($docs as $n => $doc) {
				$row = ['id' => (int)$doc->getId(), ...$doc->getData()];
				$row['highlight'] = static::highlight($doc, strip_tags($row['body']));
				$repo_ids[] = $row['repo_id'];
				$user_ids[] = $row['user_id'];
				$rbp_score = pow(static::PERSISTENCE_FACTOR, $n) * $doc->getScore();
				$issue_ids[] = $row['issue_id'];
				$items[] = ['score' => $rbp_score, 'issue' => [], 'comment' => $row];
			}

			// Append issues fetched in single query
			$issue_map = static::getDocMap('issue', $issue_ids);
			foreach ($items as &$item) {
				if (!$item['comment'] || $item['issue']) {
					continue;
				}

				$item['issue'] = $issue_map[$item['comment']['issue_id']];
				if ($item['issue']['label_ids']) {
					$label_ids = array_merge($label_ids, $item['issue']['label_ids']);
				}
				$user_ids[] = $item['issue']['user_id'];
			}
			unset($issue_ids, $issue_map);
		}

		// Append repos, users and labels to all entities
		$user_map = static::getDocMap('user', $user_ids);
		$repo_map = static::getDocMap('repo', $repo_ids);
		$label_map = $label_ids ? static::getDocMap('label', $label_ids) : [];
		foreach ($items as &$item) {
			if ($item['issue']) {
				$item['issue']['repo'] = $repo_map[$item['issue']['repo_id']];
				$item['issue']['user'] = $user_map[$item['issue']['user_id']];
				$item['issue']['labels'] = array_map(
					fn ($id) => $label_map[$id],
					$item['issue']['label_ids']
				);
			}
			if (!$item['comment']) {
				continue;
			}
			$item['comment']['repo'] = $repo_map[$item['comment']['repo_id']];
			$item['comment']['user'] = $user_map[$item['comment']['user_id']];
		}


		// Sort by score
		usort(
			$items, function ($a, $b) {
				return $b['score'] <=> $a['score'];
			}
		);

		/** @var array{open:int,closed:int} */
		$issueCounters = result(Manticore::getIssueCounters($query, $filters));
		$counters = array_merge(
			[
			'total' => $issue_count + $pull_request_count + $comment_count,
			'total_more' => $issue_relation !== 'eq' || $comment_relation !== 'eq',
			'found' => $issue_count + $pull_request_count + $comment_count,
			'found_more' => $issue_relation !== 'eq' || $comment_relation !== 'eq',
			'issue' => $issue_count,
			'issue_more' => $issue_relation !== 'eq',
			'pull_request' => $pull_request_count,
			'pull_request_more' => $issue_relation !== 'eq',
			'comment' => $comment_count,
			'comment_more' => $comment_relation !== 'eq',
			], $issueCounters
		);

		$counters = array_merge(
			$counters,
			result(static::getDocCount($query, $filters))
		);

		return ok(
			[
			'time' => $time,
			'items' => $items,
			'count' => $counters,
			]
		);
	}

	/**
	 * Get counters for issues in given repository
	 * @param string $query
	 * @param array<string,mixed> $filters
	 * @return Result<array{open:int,closed:int}>
	 */
	public static function getIssueCounters(string $query = '', array $filters = []): Result {
		$search = static::getSearch('issue', $query, $filters);
		unset($filters['state']);
		static::applyFilters($search, $filters, 'issues');
		$facets = $search
			->limit(0)
			->expression('open', 'if(closed_at=0,1,0)')
			->facet('open', 'counters', 2)
			->get()
			->getFacets();
		$counters = [
			'any' => 0,
			'open' => 0,
			'closed' => 0,
		];
		foreach ($facets['counters']['buckets'] as $bucket) {
			$counters[match ($bucket['key']) {
				1 => 'open',
				0 => 'closed',
			}] = $bucket['doc_count'];
			$counters['any'] += $bucket['doc_count'];
		}

		return ok($counters);
	}

	/**
	 * Get facets counters for related search requests for all entitites
	 * @param string $query
	 * @param array<string,mixed> $filters
	 * @return Result<array{open:int,closed:int}>
	 */
	public static function getDocCount(string $query = '', array $filters = []): Result {
		$client = static::client();

		// Initialize default counters keys
		$counters = [
			'total' => 0,
			'total_more' => '',
			'pull_request' => 0,
			'pull_request_more' => '',
			'issue' => 0,
			'issue_more' => '',
			'comment' => 0,
			'comment_more' => '',
		];

		// Get issues first
		$search = static::getSearch('issue', $query, $filters);
		unset($filters['pull_requests'], $filters['comments'], $filters['issues']);
		unset($filters['state']);
		static::applyFilters($search, $filters, 'issues');
		$facets = $search
			->limit(0)
			->expression('pull', 'if(is_pull_request=1,1,0)')
			->facet('pull', 'counters', 2)
			->get()
			->getFacets();

		foreach ($facets['counters']['buckets'] as $bucket) {
			$counters[match ($bucket['key']) {
				1 => 'pull_request',
				0 => 'issue',
			}] = $bucket['doc_count'];
			$counters['total'] += $bucket['doc_count'];
		}

		// Get comments now=
		$search = static::getSearch('comment', $query, $filters);
		static::applyFilters($search, $filters, 'comments');
		$facets = $search
			->limit(0)
			->facet('repo_id', 'counters', sizeof($filters['common']['repo_id'] ?? []))
			->get()
			->getFacets();
		$counters['comment'] = array_sum(
			array_map(
				fn ($doc) => $doc['doc_count'],
				$facets['counters']['buckets'] ?? ['doc_count' => 0]
			)
		);
		$counters['total'] += $counters['comment'];
		return ok($counters);
	}

	/**
	 * Fetch unique users by given field from the issues
	 * @param  array<int>         $repo_ids
	 * @param  string      $field
	 * @param  int $max
	 * @return Result<array<User>>
	 */
	public static function getUsers(array $repo_ids, string $field, int $max = 1000): Result {
		$client = static::client();
		$index = $client->index('issue');
		$search = $index->search('');
		$facets = $search
			->limit(0)
			->filter('repo_id', 'in', $repo_ids)
			->facet($field, 'users', $max, 'COUNT(*)')
			->get()
			->getFacets();

		$user_ids = array_filter(array_column($facets['users']['buckets'], 'key'));
		$user_counts = array_filter(array_column($facets['users']['buckets'], 'doc_count'));
		$sorting = array_flip($user_ids);
		$index = $client->index('user');
		$docs = $index->getDocumentByIds($user_ids);
		$users = [];
		foreach ($docs as $doc) {
			$id = (int)$doc->getId();
			$users[$sorting[$id]] = [
				'id' => $id,
				'count' => $user_counts[$sorting[$id]],
				...$doc->getData(),
			];
		}
		ksort($users);

		return ok(array_values($users));
	}


	/**
	 * Fetch repositories that we have for given org
	 * @param  int         $org_id
	 * @param  int $max
	 * @return Result<array<Repo>>
	 */
	public static function getRepos(int $org_id, int $max = 1000): Result {
		$client = static::client();
		$index = $client->index('repo');
		$search = $index->search('');
		$docs = $search
			->limit($max)
			->filter('org_id', $org_id)
			->get();
		$repos = [];
		foreach ($docs as $doc) {
			$id = (int)$doc->getId();
			$repos[] = [
				'id' => $id,
				...$doc->getData(),
			];
		}
		return ok($repos);
	}

	/**
	 * @param  int $limit
	 * @return Result<array<Repo>>
	 */
	public static function getShowcaseRepos(int $limit = 1000): Result {
		$client = static::client();
		$index = $client->index('repo');
		$search = $index->search('');
		$organizations = config('github.organizations');
		$orgIndex = $client->index('org');
		$results = $orgIndex->search('')->filter('name', $organizations)->get();
		$org_ids = array_map(fn ($doc) => (int)$doc->getId(), iterator_to_array($results));
		$search->filter('org_id', $org_ids);
		$docs = $search
			->limit($limit)
			->filter('is_indexing', false)
			->sort('issues', 'desc')
			->get();
		$result = [];
		foreach ($docs as $doc) {
			$result[] = new Repo(
				[
				'id' => (int)$doc->getId(),
				...$doc->getData(),
				]
			);
		}

		return ok($result);
	}

	/**
	 * Fetch unique labels by given field from the issues
	 * @param  array<int>         $repo_ids
	 * @param  int $max
	 * @return Result<array<User>>
	 */
	public static function getLabels(array $repo_ids, int $max = 1000): Result {
		$client = static::client();
		$index = $client->index('issue');
		$search = $index->search('');
		$facets = $search
			->limit(0)
			->filter('repo_id', 'in', $repo_ids)
			->facet('label_ids', 'labels', $max, 'COUNT(*)')
			->get()
			->getFacets();
		$label_ids = array_filter(array_column($facets['labels']['buckets'], 'key'));
		$label_counts = array_filter(array_column($facets['labels']['buckets'], 'doc_count'));
		$sorting = array_flip($label_ids);
		$index = $client->index('label');
		$docs = $index->getDocumentByIds($label_ids);
		$labels = [];
		foreach ($docs as $doc) {
			$id = (int)$doc->getId();
			$labels[$sorting[$id]] = [
				'id' => $id,
				'count' => $label_counts[$sorting[$id]],
				...$doc->getData(),
			];
		}
		ksort($labels);
		return ok(array_values($labels));
	}

	/**
	 * Get comment ranges for the repo with counts for each
	 * @param  array<int>    $repo_ids
	 * @param  array<int>  $values List of values to use for aggregation
	 * @return Result<array<mixed>>
	 */
	public static function getCommentRanges(array $repo_ids, array $values): Result {
		$client = static::client();
		$index = $client->index('issue');
		$search = $index->search('');
		$range = implode(',', $values);
		$facets = $search
			->limit(0)
			->filter('repo_id', 'in', $repo_ids)
			->expression('range', "INTERVAL(comments, $range)")
			->facet('range', 'counters', sizeof($values) + 1)
			->get()
			->getFacets();
		uasort($facets['counters']['buckets'], fn ($a, $b) => $a['key'] < $b['key'] ? -1 : 1);
		$docs = [];
		$n = 1;
		foreach ($facets['counters']['buckets'] as $bucket) {
			$doc = [
				'id' => $n++,
				'count' => $bucket['doc_count'],
			];

			if ($bucket['key'] > 0) {
				$doc['min'] = ($values[$bucket['key'] - 1] + 1);
			}

			if (isset($values[$bucket['key']])) {
				$doc['max'] = $values[$bucket['key']];
			}

			$doc['name'] = match (true) {
				!isset($doc['min']) => "< {$doc['max']}",
				!isset($doc['max']) => "> {$doc['min']}",
				default => "{$doc['min']} – {$doc['max']}",
			};

			$docs[] = $doc;
		}
		return ok($docs);
	}

	/**
	 * Generate higlihght with begin-end cut and using default value
	 * @param  ResultHit $doc
	 * @param  string    $body
	 * @return string
	 */
	protected static function highlight(ResultHit $doc, string $body): string {
		$highlights = array_filter(array_map(trim(...), $doc->getHighlight()['body']));
		$body = trim($body);
		if (!$highlights) {
			$highlights = [$body];
		}
		$text = implode('…', $highlights);
		if (substr($text, 0, 5) !== substr($body, 0, 5)) {
			$text = "…{$text}";
		}

		if (substr($text, -5, 5) !== substr($body, -5, 5)) {
			$text = "{$text}…";
		}

		// TODO: temporarely solution to fix dots issue
		return str_replace('...…', '…', $text);
	}

	/**
	 * Apply sorting to the active serach instance
	 * @param  Search $search
	 * @param  string $sort
	 * @return void
	 */
	protected static function applySorting(Search $search, string $sort): void {
		$sorting = match ($sort) {
			'most-commented' => ['comments', 'desc'],
			'least-commented' => ['comments', 'asc'],
			'newest' => ['created_at', 'desc'],
			'oldest' => ['created_at', 'asc'],
			'recently-updated' => ['updated_at', 'desc'],
			'least-recently-updated' => ['updated_at', 'asc'],
			'most-reacted' => ['reactions.total_count', 'desc'],
			'most-positive-reacted' => ['positive_reactions', 'desc'],
			'most-negative-reacted' => ['negative_reactions', 'desc'],
			default => [], // best-match
		};
		if (!$sorting) {
			return;
		}

		if ($sort === 'most-positive-reacted') {
			$search->expression(
				'positive_reactions',
				'integer(reactions.`+1`) + integer(reactions.hooray) + integer(reactions.heart) + integer(reactions.rocket)'
			);
		} elseif ($sort === 'most-negative-reacted') {
			$search->expression(
				'negative_reactions',
				'integer(reactions.`-1`) + integer(reactions.laugh) + integer(reactions.confused)'
			);
		}
		$search->sort(...$sorting);
	}

	/**
	 * Apply filters to type of index
	 * @param  Search $search
	 * @param  array  $filters
	 * @param  string $type One of comments or issues
	 * @return void
	 */
	protected static function applyFilters(Search $search, array $filters, string $type): void {
		$issue_filters = array_merge($filters['common'] ?? [], $filters['issue'] ?? []);
		$comment_filters = array_merge($filters['common'] ?? [], $filters['comment'] ?? []);

		if ($type === 'issues') {
			// Special state filter
			if (isset($filters['state'])) {
				$fn = match ($filters['state']) {
					'open' => 'filter',
					'closed' => 'notFilter',
					default => null,
				};
				if (isset($fn)) {
					$search->$fn('closed_at', 0);
				}
			}
			$search_all = ($filters['issues'] ?? true)
			&& ($filters['pull_requests'] ?? true)
				// && ($filters['comments'] ?? true)
			;

			if (!$search_all && isset($filters['pull_requests'])) {
				$search->filter('is_pull_request', $filters['pull_requests']);
			}

			if (isset($filters['comment_ranges'])) {
				$condition = Search::FILTER_AND;
				foreach ($filters['comment_ranges'] as $range) {
					if (isset($range['min'])) {
						$search->filter('comments', 'gt', $range['min'], $condition);
						$condition = Search::FILTER_OR;
					}

					if (!isset($range['max'])) {
						continue;
					}

					$search->filter('comments', 'lte', $range['max'], $condition);
					$condition = Search::FILTER_OR;
				}
			}

			foreach ($issue_filters as $key => $value) {
				$search->filter($key, is_array($value) ? 'in' : 'equals', $value);
			}
		}

		if ($type !== 'comments') {
			return;
		}

		foreach ($comment_filters as $key => $value) {
			$search->filter($key, is_array($value) ? 'in' : 'equals', $value);
		}
	}

	/**
	 * By default we use BM15 ranker, to make it better we change to BM25
	 * @param  Search $search
	 * @return void
	 */
	protected static function applyConfig(Search $search): void {
		$search
			->option('cutoff', 0)
			->option('ranker', 'expr(\'10000 * bm25f(1.2,0.75)\')');
	}

	/**
	 * Helper method to get the doc map indexed by id by using provided ides
	 * @param  string $table
	 * @param  array  $ids
	 * @return array<int,array<mixed>>
	 */
	protected static function getDocMap(string $table, array $ids): array {
		$ids = array_values(array_unique($ids));
		if (!$ids) {
			return [];
		}

		$client = static::client();
		$Index = $client->index($table);
		$docs = $Index->getDocumentByIds($ids);
		$map = [];
		foreach ($docs as $doc) {
			$map[$doc->getId()] = $doc->getData();
		}

		return $map;
	}

	/**
	 * Get all emails that subscribed to the repo status on index
	 * @param  int    $repo_id
	 * @return Generator<string> iterator with email of the customer
	 */
	public static function getRepoSubscribers(int $repo_id): Generator {
		$client = static::client();
		$Index = $client->index('notification');
		$docs = $Index
			->search('')
			->limit(1000)
			->filter('repo_id', $repo_id)
			->filter('is_sent', false)
			->get();

		foreach ($docs as $doc) {
			$data = $doc->getData();

			// First update document and mark it processed
			$data['is_sent'] = true;
			$data['updated_at'] = time();
			$Index->replaceDocument($data, $doc->getId());

			// Return the email
			yield $data['email'];
		}
	}

	/**
	 * Method to add subscriber to the repository
	 * @param int    $repo_id
	 * @param string $email
	 * @return Result<Notification>
	 */
	public static function addRepoSubscriber(int $repo_id, string $email): Result {
		$client = static::client();
		$Index = $client->index('notification');
		$id = hexdec(substr(md5("$email:$repo_id"), 0, 12));
		$doc = $Index->getDocumentById($id);
		$data = $doc?->getData();
		if ($data) {
			$data['is_sent'] = false;
		} else {
			$data = [
				'email' => $email,
				'repo_id' => $repo_id,
				'is_sent' => false,
				'created_at' => time(),
			];
		}
		$data['updated_at'] = time();

		$Notification = Notification::fromArray($data);
		$Index->replaceDocument($Notification->toArray(), $id);

		return ok($Notification);
	}

	/**
	 * Get the search object by table name
	 * @param  string $table
	 * @param  string $query
	 * @param  array  $filters
	 * @return Search
	 */
	protected static function getSearch(string $table, string $query, array $filters): Search {
		$client = static::client();
		$Index = $client->index($table);
		$semantic_search_only = $filters['semantic_search_only'] ?? false;
		$query = $semantic_search_only ? '' : $query;
		$Query = new BoolQuery();
		if (isset($filters['embeddings'])) {
			$Query = new KnnQuery('embeddings', $filters['embeddings'], 1000);
		}

		if ($query) {
			$QueryString = new QueryString($query);
			$Query->must($QueryString);
		}

		return $Index->search($Query);
	}
}
