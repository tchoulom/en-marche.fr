<?php

namespace Tests\AppBundle\Controller;

use AppBundle\DataFixtures\ORM\LoadAdherentData;
use AppBundle\DataFixtures\ORM\LoadClarificationData;
use AppBundle\DataFixtures\ORM\LoadEventData;
use AppBundle\DataFixtures\ORM\LoadPageData;
use AppBundle\DataFixtures\ORM\LoadProposalData;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\AppBundle\SqliteWebTestCase;

/**
 * @group functionnal
 */
class PageControllerTest extends SqliteWebTestCase
{
    use ControllerTestTrait;

    /**
     * @dataProvider providePages
     */
    public function testPages(string $path)
    {
        $this->client->request(Request::METHOD_GET, $path);
        $this->assertResponseStatusCode(Response::HTTP_OK, $response = $this->client->getResponse());
    }

    public function testCommitteesMap()
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/le-mouvement/la-carte');

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertSame(1, $crawler->filter('html:contains("La carte des comités")')->count());
        $this->assertContains('10 adhérents', $crawler->filter('#counter-adherents')->text());
        $this->assertContains('6 comités', $crawler->filter('#counter-committees')->text());
        $this->assertContains('10 événements', $crawler->filter('#counter-events')->text());
    }

    public function testCommitteesEventsMap()
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/evenements/la-carte');

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertSame(1, $crawler->filter('html:contains("La carte des événements")')->count());
        $this->assertContains('Tous (4)', $crawler->filter('.events-map-categories--all')->first()->text());
    }

    public function providePages()
    {
        return [
            ['/emmanuel-macron'],
            ['/emmanuel-macron/revolution'],
            ['/emmanuel-macron/le-programme'],
            ['/emmanuel-macron/le-programme/produire-en-france-et-sauver-la-planete'],
            ['/emmanuel-macron/le-programme/eduquer-tous-nos-enfants'],
            ['/emmanuel-macron/desintox'],
            ['/emmanuel-macron/desintox/heritier-hollande-traite-quiquennat'],
            ['/le-mouvement'],
            ['/le-mouvement/notre-organisation'],
            ['/le-mouvement/les-comites'],
            ['/le-mouvement/devenez-benevole'],
            ['/le-mouvement/legislatives'],
            ['/le-mouvement/la-carte'],
            ['/bot'],
            ['/elles-marchent'],
            ['/mentions-legales'],
        ];
    }

    public function testProposalDraft()
    {
        $this->client->request(Request::METHOD_GET, '/emmanuel-macron/le-programme/mieux-vivre-de-son-travail');
        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $this->client->getResponse());
    }

    protected function setUp()
    {
        parent::setUp();

        $this->init([
            LoadAdherentData::class,
            LoadEventData::class,
            LoadPageData::class,
            LoadProposalData::class,
            LoadClarificationData::class,
        ]);
    }

    protected function tearDown()
    {
        $this->kill();

        parent::tearDown();
    }
}
