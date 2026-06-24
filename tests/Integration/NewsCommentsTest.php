<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Integration;

use NagaCommerce\SDK\Exceptions\ApiException;
use PHPUnit\Framework\Attributes\Test;

/**
 * /news-comments endpoints — visitor comment CRUD on news articles.
 *
 * Coverage:
 *   - create (defaults + validation), get, list (filter by news_id/status),
 *     update (partial + status transition), delete
 *   - newsnumcomments is recomputed server-side on approve/delete; we don't
 *     assert the article counter here (covered by the core unit tests).
 *
 * Each test creates its own article so runs stay independent. No cleanup —
 * uid()-tagged content keeps runs distinct.
 */
final class NewsCommentsTest extends IntegrationTestCase
{
    private function makeArticle(): int
    {
        $created = $this->client->news()->createArticle([
            'newstitle' => $this->uid('CommentArticle'),
        ])->getData();
        return (int)$created['newsid'];
    }

    #[Test]
    public function create_comment_and_get_back(): void
    {
        $this->requireScope('news.write');
        $this->requireScope('news.read');

        $articleId = $this->makeArticle();

        $r = $this->client->news()->createComment([
            'news_id'   => $articleId,
            'text'      => 'First!',
            'from_name' => 'Tester',
        ]);
        $this->assertSame(201, $r->getStatusCode());
        $row = $r->getData();
        $commentId = (int)$row['commentid'];
        $this->assertGreaterThan(0, $commentId);
        $this->assertSame($articleId, (int)$row['comnewsid']);
        $this->assertSame('First!', $row['comtext']);
        $this->assertSame(0, (int)$row['comstatus'], 'comments default to pending moderation');

        // Round-trip via getComment.
        $back = $this->client->news()->getComment($commentId)->getData();
        $this->assertSame($commentId, (int)$back['commentid']);
        $this->assertSame('Tester', $back['comfromname']);
    }

    #[Test]
    public function create_without_news_id_returns_400(): void
    {
        $this->requireScope('news.write');
        try {
            $this->client->news()->createComment(['text' => 'orphan']);
            $this->fail('expected 400');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
        }
    }

    #[Test]
    public function create_without_text_returns_400(): void
    {
        $this->requireScope('news.write');
        $articleId = $this->makeArticle();
        try {
            $this->client->news()->createComment(['news_id' => $articleId, 'text' => '   ']);
            $this->fail('expected 400');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
        }
    }

    #[Test]
    public function create_on_unknown_article_returns_400(): void
    {
        $this->requireScope('news.write');
        try {
            $this->client->news()->createComment(['news_id' => 999999999, 'text' => 'nope']);
            $this->fail('expected 400');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
        }
    }

    #[Test]
    public function list_filters_by_news_id(): void
    {
        $this->requireScope('news.write');
        $this->requireScope('news.read');

        $articleId = $this->makeArticle();
        $this->client->news()->createComment(['news_id' => $articleId, 'text' => 'one']);
        $this->client->news()->createComment(['news_id' => $articleId, 'text' => 'two']);

        $r = $this->client->news()->listComments(['news_id' => $articleId]);
        $this->assertSame(200, $r->getStatusCode());
        $rows = $r->getData();
        $this->assertGreaterThanOrEqual(2, count($rows));
        foreach ($rows as $row) {
            $this->assertSame($articleId, (int)$row['comnewsid']);
        }
    }

    #[Test]
    public function update_changes_text_and_status(): void
    {
        $this->requireScope('news.write');
        $this->requireScope('news.read');

        $articleId = $this->makeArticle();
        $created = $this->client->news()->createComment([
            'news_id' => $articleId,
            'text'    => 'before',
        ])->getData();
        $commentId = (int)$created['commentid'];

        $r = $this->client->news()->updateComment($commentId, [
            'text'   => 'after',
            'status' => 1,
        ]);
        $this->assertSame(200, $r->getStatusCode());
        $row = $r->getData();
        $this->assertSame('after', $row['comtext']);
        $this->assertSame(1, (int)$row['comstatus']);
    }

    #[Test]
    public function update_unknown_comment_returns_404(): void
    {
        $this->requireScope('news.write');
        try {
            $this->client->news()->updateComment(999999999, ['text' => 'x']);
            $this->fail('expected 404');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    #[Test]
    public function delete_returns_deleted_flag_and_404s_after(): void
    {
        $this->requireScope('news.write');
        $this->requireScope('news.read');
        $this->requireScope('news.delete');

        $articleId = $this->makeArticle();
        $created = $this->client->news()->createComment([
            'news_id' => $articleId,
            'text'    => 'to delete',
            'status'  => 1,
        ])->getData();
        $commentId = (int)$created['commentid'];

        $r = $this->client->news()->deleteComment($commentId);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertTrue($r->getData()['deleted'] ?? false);

        try {
            $this->client->news()->getComment($commentId);
            $this->fail('get after delete should 404');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }
}
