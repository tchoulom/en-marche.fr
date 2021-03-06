<?php

namespace Tests\AppBundle\Controller;

use AppBundle\DataFixtures\ORM\LoadAdherentData;
use AppBundle\DataFixtures\ORM\LoadEventCategoryData;
use AppBundle\DataFixtures\ORM\LoadEventData;
use AppBundle\Entity\Event;
use AppBundle\Entity\CommitteeFeedItem;
use AppBundle\Mailjet\Message\CommitteeNewFollowerMessage;
use AppBundle\Mailjet\Message\EventNotificationMessage;
use AppBundle\Mailjet\Message\CommitteeMessageNotificationMessage;
use AppBundle\Repository\EventRepository;
use AppBundle\Repository\CommitteeFeedItemRepository;
use AppBundle\Repository\CommitteeMembershipRepository;
use AppBundle\Repository\CommitteeRepository;
use AppBundle\Repository\MailjetEmailRepository;
use AppBundle\Entity\Committee;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\AppBundle\MysqlWebTestCase;

/**
 * @group functional
 */
class CommitteeControllerTest extends MysqlWebTestCase
{
    use ControllerTestTrait;

    /* @var MailjetEmailRepository */
    private $emailRepository;

    /* @var CommitteeRepository */
    private $committeeRepository;

    /* @var EventRepository */
    private $committeeEventRepository;

    /* @var CommitteeFeedItemRepository */
    private $committeeFeedItemRepository;

    /* @var CommitteeMembershipRepository */
    private $committeeMembershipRepository;

    public function testAnonymousUserIsNotAllowedToFollowCommittee()
    {
        $committeeUrl = sprintf('/comites/%s/%s', LoadAdherentData::COMMITTEE_3_UUID, 'en-marche-dammarie-les-lys');

        $crawler = $this->client->request(Request::METHOD_GET, $committeeUrl);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertFalse($this->seeFollowLink($crawler));
        $this->assertFalse($this->seeUnfollowLink($crawler));
        $this->assertTrue($this->seeRegisterLink($crawler));
    }

    /**
     * @dataProvider provideHostCredentials
     */
    public function testAuthenticatedCommitteeHostCannotUnfollowCommittee(string $emailAddress, string $password)
    {
        $crawler = $this->authenticateAsAdherent($this->client, $emailAddress, $password);

        $crawler = $this->client->click($crawler->selectLink('En Marche Paris 8')->link());
        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());

        // Unfollow link must be disabled
        $this->assertSame('disabled', $crawler->filter('.committee-unfollow')->attr('disabled'));

        // Other follower/register links must not exist
        $this->assertFalse($this->seeFollowLink($crawler));
        $this->assertFalse($this->seeRegisterLink($crawler, 0));
    }

    public function testAuthenticatedAdherentCanFollowCommittee()
    {
        $this->authenticateAsAdherent($this->client, 'carl999@example.fr', 'secret!12345');

        // Browse to the committee details page
        $committeeUrl = sprintf('/comites/%s/%s', LoadAdherentData::COMMITTEE_3_UUID, 'en-marche-dammarie-les-lys');

        $crawler = $this->client->request(Request::METHOD_GET, $committeeUrl);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertSame('2 adhérents', $crawler->filter('.committee__card > .committee-members')->text());
        $this->assertTrue($this->seeFollowLink($crawler));
        $this->assertFalse($this->seeUnfollowLink($crawler));
        $this->assertFalse($this->seeRegisterLink($crawler, 0));

        // Emulate POST request to follow the committee.
        $token = $crawler->selectButton('Suivre ce comité')->attr('data-csrf-token');
        $this->client->request(Request::METHOD_POST, $committeeUrl.'/rejoindre', ['token' => $token]);

        // Email sent to the host
        $this->assertCountMails(1, CommitteeNewFollowerMessage::class, 'francis.brioul@yahoo.com');

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());

        // Refresh the committee details page
        $crawler = $this->client->request(Request::METHOD_GET, $committeeUrl);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertSame('3 adhérents', $crawler->filter('.committee__card > .committee-members')->text());
        $this->assertFalse($this->seeFollowLink($crawler));
        $this->assertTrue($this->seeUnfollowLink($crawler));
        $this->assertFalse($this->seeRegisterLink($crawler, 0));

        // Emulate POST request to unfollow the committee.
        $token = $crawler->selectButton('Quitter ce comité')->attr('data-csrf-token');
        $this->client->request(Request::METHOD_POST, $committeeUrl.'/quitter', ['token' => $token]);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());

        // Refresh the committee details page
        $crawler = $this->client->request(Request::METHOD_GET, $committeeUrl);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertSame('2 adhérents', $crawler->filter('.committee__card > .committee-members')->text());
        $this->assertTrue($this->seeFollowLink($crawler));
        $this->assertFalse($this->seeUnfollowLink($crawler));
        $this->assertFalse($this->seeRegisterLink($crawler, 0));
    }

    public function testCommitteeFollowerIsNotAllowedToEditCommitteeInformation()
    {
        $crawler = $this->authenticateAsAdherent($this->client, 'carl999@example.fr', 'secret!12345');
        $this->client->click($crawler->selectLink('En Marche Paris 8')->link());

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());

        $this->client->request('GET', sprintf('%s/editer', $this->client->getRequest()->getPathInfo()));

        $this->assertResponseStatusCode(Response::HTTP_FORBIDDEN, $this->client->getResponse());
    }

    public function testCommitteeHostCanEditCommitteeInformation()
    {
        $crawler = $this->authenticateAsAdherent($this->client, 'gisele-berthoux@caramail.com', 'ILoveYouManu');
        $crawler = $this->client->click($crawler->selectLink('En Marche Paris 8')->link());

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());

        $crawler = $this->client->click($crawler->selectLink('Éditer le comité')->link());

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());

        // Submit the committee form with invalid data
        $crawler = $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'committee' => [
                'name' => 'F',
                'description' => 'F',
                'address' => [
                    'address' => '',
                    'country' => 'FR',
                    'postalCode' => '99999',
                    'city' => '10102-45029',
                ],
                'facebookPageUrl' => 'yo',
                'twitterNickname' => '@!!',
                'googlePlusPageUrl' => 'yo',
            ],
        ]));

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertSame(8, $crawler->filter('#edit-committee-form .form__errors > li')->count());
        $this->assertSame('Cette valeur n\'est pas un code postal français valide.', $crawler->filter('#committee-address > .form__errors > .form__error')->eq(0)->text());
        $this->assertSame("Votre adresse n'est pas reconnue. Vérifiez qu'elle soit correcte.", $crawler->filter('#committee-address > .form__errors > li')->eq(1)->text());
        $this->assertSame("L'adresse est obligatoire.", $crawler->filter('#field-address > .form__errors > li')->text());
        $this->assertSame('Cette chaîne est trop courte. Elle doit avoir au minimum 2 caractères.', $crawler->filter('#field-name > .form__errors > li')->text());
        $this->assertSame('Votre texte de description est trop court. Il doit compter 5 caractères minimum.', $crawler->filter('#field-description > .form__errors > li')->text());
        $this->assertSame("Cette valeur n'est pas une URL valide.", $crawler->filter('#field-facebook-page-url > .form__errors > li')->text());
        $this->assertSame('Un identifiant Twitter ne peut contenir que des lettres, des chiffres et des underscores.', $crawler->filter('#field-twitter-nickname > .form__errors > li')->text());
        $this->assertSame("Cette valeur n'est pas une URL valide.", $crawler->filter('#field-googleplus-page-url > .form__errors > li')->text());

        // Submit the committee form with valid data to create committee
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'committee' => [
                'name' => 'Clichy est En Marche !',
                'description' => 'Comité français En Marche ! de la ville de Clichy',
                'address' => [
                    'country' => 'FR',
                    'address' => '92 bld victor hugo',
                    'postalCode' => '92110',
                    'city' => '92110-92024',
                    'cityName' => '',
                ],
                'facebookPageUrl' => 'https://www.facebook.com/EnMarcheClichy',
                'twitterNickname' => '@enmarcheclichy',
                'googlePlusPageUrl' => 'https://plus.google.com/+EnMarcheavecEmmanuelMacron?hl=fr',
            ],
        ]));

        $this->assertStatusCode(Response::HTTP_FOUND, $this->client);

        // Follow the redirect and check the adherent can see the committee edit page again
        $crawler = $this->client->followRedirect();
        $this->assertStatusCode(Response::HTTP_OK, $this->client);
        $this->assertContains('Les informations du comité ont été mises à jour avec succès.', $crawler->filter('#notice-flashes')->text());
        $this->assertSame('Clichy est En Marche !', $crawler->filter('#committee_name')->attr('value'));
        $this->assertSame('Comité français En Marche ! de la ville de Clichy', $crawler->filter('#committee_description')->text());
    }

    public function testCommitteeFollowerIsNotAllowedToPublishNewEvent()
    {
        $crawler = $this->authenticateAsAdherent($this->client, 'carl999@example.fr', 'secret!12345');
        $this->client->click($crawler->selectLink('En Marche Paris 8')->link());

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());

        $this->client->request('GET', sprintf('%s/evenements/ajouter', $this->client->getRequest()->getPathInfo()));

        $this->assertResponseStatusCode(Response::HTTP_FORBIDDEN, $this->client->getResponse());
    }

    public function testCommitteeHostCanPublishNewEvent()
    {
        $crawler = $this->authenticateAsAdherent($this->client, 'gisele-berthoux@caramail.com', 'ILoveYouManu');
        $crawler = $this->client->click($crawler->selectLink('En Marche Paris 8')->link());

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());

        $crawler = $this->client->click($crawler->selectLink('Créer un événement')->link());

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());

        $eventCategory = $this->getEventCategoryIdForName(LoadEventCategoryData::LEGACY_EVENT_CATEGORIES['CE003']);

        // Submit the committee event form with invalid data
        $crawler = $this->client->submit($crawler->selectButton('Créer cet événement')->form([
            'committee_event' => [
                'name' => 'F',
                'description' => 'F',
                'category' => $eventCategory,
                'address' => [
                    'country' => 'FR',
                    'postalCode' => '99999',
                    'city' => '10102-45029',
                ],
                'beginAt' => [
                    'date' => [
                        'year' => '2017',
                        'month' => '3',
                        'day' => '2',
                    ],
                    'time' => [
                        'hour' => '14',
                        'minute' => '30',
                    ],
                ],
                'finishAt' => [
                    'date' => [
                        'year' => '2017',
                        'month' => '3',
                        'day' => '1',
                    ],
                    'time' => [
                        'hour' => '19',
                        'minute' => '0',
                    ],
                ],
                'capacity' => 'zero',
            ],
        ]));

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertSame(7, $crawler->filter('#committee-event-form .form__errors > li')->count());
        $this->assertSame('Cette chaîne est trop courte. Elle doit avoir au minimum 5 caractères.', $crawler->filter('#committee-event-name-field .form__error')->text());
        $this->assertSame('Cette chaîne est trop courte. Elle doit avoir au minimum 10 caractères.', $crawler->filter('#committee-event-description-field .form__error')->text());
        $this->assertSame('Cette valeur n\'est pas valide.', $crawler->filter('#committee-event-capacity-field .form__error')->text());
        $this->assertSame("Cette valeur n'est pas un code postal français valide.", $crawler->filter('#committee-event-address > .form__errors > .form__error')->eq(0)->text());
        $this->assertSame("Votre adresse n'est pas reconnue. Vérifiez qu'elle soit correcte.", $crawler->filter('#committee-event-address > .form__errors > li')->eq(1)->text());
        $this->assertSame("L'adresse est obligatoire.", $crawler->filter('#committee-event-address-address-field > .form__errors > li')->text());
        $this->assertSame("La date de fin de l'événement doit être postérieure à la date de début.", $crawler->filter('#committee-event-finishat-field > .form__errors > li')->text());

        // Submit the committee form with valid data to create the new committee event
        $this->client->submit($crawler->selectButton('Créer cet événement')->form([
            'committee_event' => [
                'name' => " ♻ Débat sur l'écologie ♻ ",
                'description' => ' ♻ Cette journée sera consacrée à un grand débat sur la question écologique. ♻ ',
                'category' => $eventCategory,
                'address' => [
                    'address' => '6 rue Neyret',
                    'country' => 'FR',
                    'postalCode' => '69001',
                    'city' => '69001-69381',
                ],
                'beginAt' => [
                    'date' => [
                        'year' => '2022',
                        'month' => '3',
                        'day' => '2',
                    ],
                    'time' => [
                        'hour' => '9',
                        'minute' => '30',
                    ],
                ],
                'finishAt' => [
                    'date' => [
                        'year' => '2022',
                        'month' => '3',
                        'day' => '2',
                    ],
                    'time' => [
                        'hour' => '19',
                        'minute' => '0',
                    ],
                ],
                'capacity' => '1500',
            ],
        ]));

        $this->assertStatusCode(Response::HTTP_FOUND, $this->client);
        $this->assertInstanceOf(Event::class, $event = $this->committeeEventRepository->findMostRecentEvent());
        $this->assertSame("Débat sur l'écologie", $event->getName());
        $this->assertSame('Cette journée sera consacrée à un grand débat sur la question écologique.', $event->getDescription());
        $this->assertCountMails(1, EventNotificationMessage::class, 'jacques.picard@en-marche.fr');
        $this->assertCountMails(1, EventNotificationMessage::class, 'gisele-berthoux@caramail.com');
        $this->assertCountMails(1, EventNotificationMessage::class, 'luciole1989@spambox.fr');
        $this->assertCountMails(0, EventNotificationMessage::class, 'carl999@example.fr');

        $eventItem = $this->committeeFeedItemRepository->findMostRecentFeedEvent(LoadAdherentData::COMMITTEE_1_UUID);
        $this->assertInstanceOf(CommitteeFeedItem::class, $eventItem);
        $this->assertInstanceOf(Event::class, $eventItem->getEvent());
        $this->assertSame("Débat sur l'écologie", (string) $eventItem->getEvent());

        // Follow the redirect and check the adherent can see the committee page
        $crawler = $this->client->followRedirect();
        $this->assertStatusCode(Response::HTTP_OK, $this->client);
        $this->assertContains('Le nouvel événement a bien été créé et publié sur la page du comité.', $crawler->filter('#notice-flashes')->text());
        $this->assertSame('Débat sur l\'écologie | En Marche !', $crawler->filter('title')->text());
        $this->assertSame('Débat sur l\'écologie', $crawler->filter('.committee-event-name')->text());
        $this->assertSame('Organisé par Gisele Berthoux du comité En Marche Paris 8', trim(preg_replace('/\s+/', ' ', $crawler->filter('.committee-event-organizer')->text())));
        $this->assertSame('Mercredi 2 mars 2022, 9h30', $crawler->filter('.committee-event-date')->text());
        $this->assertSame('6 rue Neyret, 69001 Lyon 1er', $crawler->filter('.committee-event-address')->text());
        $this->assertSame('Cette journée sera consacrée à un grand débat sur la question écologique.', $crawler->filter('.committee-event-description')->text());
    }

    public function testApprovedCommitteePageIsViewableByAnyone()
    {
        $committeeUrl = sprintf('/comites/%s/%s', LoadAdherentData::COMMITTEE_3_UUID, 'en-marche-dammarie-les-lys');

        // Anonymous
        $crawler = $this->client->request(Request::METHOD_GET, $committeeUrl);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertCountTimelineMessages($crawler, 2);
        $this->assertSeeTimelineMessages($crawler, [
            ['Jacques Picard', 'Connectez-vous'],
            ['Jacques Picard', 'Connectez-vous'],
        ]);

        // Adherent
        $this->authenticateAsAdherent($this->client, 'carl999@example.fr', 'secret!12345');

        $crawler = $this->client->request(Request::METHOD_GET, $committeeUrl);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertCountTimelineMessages($crawler, 2);
        $this->assertSeeTimelineMessages($crawler, [
            ['Jacques Picard', 'À la recherche de volontaires !'],
            ['Jacques Picard', 'Lancement du comité !'],
        ]);

        $this->logout($this->client);

        // Member
        $this->authenticateAsAdherent($this->client, 'francis.brioul@yahoo.com', 'Champion20');

        $crawler = $this->client->request(Request::METHOD_GET, $committeeUrl);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertCountTimelineMessages($crawler, 2);
        $this->assertSeeTimelineMessages($crawler, [
            ['Jacques Picard', 'À la recherche de volontaires !'],
            ['Jacques Picard', 'Lancement du comité !'],
        ]);
    }

    public function testUnapprovedCommitteeIsViewableByItsCreator()
    {
        $committeeUrl = sprintf('/comites/%s/%s', LoadAdherentData::COMMITTEE_2_UUID, 'en-marche-marseille-3');

        // Adherent
        $this->authenticateAsAdherent($this->client, 'carl999@example.fr', 'secret!12345');

        $this->client->request(Request::METHOD_GET, $committeeUrl);

        $this->assertResponseStatusCode(Response::HTTP_FORBIDDEN, $this->client->getResponse());

        $this->logout($this->client);

        // Creator
        $this->authenticateAsAdherent($this->client, 'benjyd@aol.com', 'HipHipHip');

        $this->client->request(Request::METHOD_GET, $committeeUrl);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
    }

    public function testAnonymousGuestCanShowCommitteePage()
    {
        $committeeUrl = sprintf('/comites/%s/%s', LoadAdherentData::COMMITTEE_1_UUID, 'en-marche-paris-8');

        $crawler = $this->client->request(Request::METHOD_GET, $committeeUrl);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertTrue($this->seeRegisterLink($crawler), 'The guest should see the "register link"');
        $this->assertFalse($this->seeFollowLink($crawler), 'The guest should not see the "follow link"');
        $this->assertFalse($this->seeUnfollowLink($crawler), 'The guest should not see the "unfollow link"');
        $this->assertTrue($this->seeMembersCount($crawler, 4), 'The guest should see the members count');
        $this->assertTrue($this->seeHosts($crawler, 2), 'The guest should see the hosts');
        $this->assertTrue($this->seeHostsContactLink($crawler, 2), 'The guest should see the hosts contact link');
        $this->assertFalse($this->seeHostNav($crawler), 'The guest should not see the host navigation');
        $this->assertSeeSocialLinks($crawler, $this->committeeRepository->findOneByUuid(LoadAdherentData::COMMITTEE_1_UUID));
        $this->assertFalse($this->seeMessageForm($crawler));
    }

    public function testAuthenticatedAdherentCanShowCommitteePage()
    {
        $this->authenticateAsAdherent($this->client, 'benjyd@aol.com', 'HipHipHip');

        $committeeUrl = sprintf('/comites/%s/%s', LoadAdherentData::COMMITTEE_1_UUID, 'en-marche-paris-8');

        $crawler = $this->client->request(Request::METHOD_GET, $committeeUrl);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertFalse($this->seeRegisterLink($crawler, 0), 'The adherent should not see the "register link"');
        $this->assertTrue($this->seeFollowLink($crawler), 'The adherent should see the "follow link"');
        $this->assertFalse($this->seeUnfollowLink($crawler), 'The adherent should not see the "unfollow link"');
        $this->assertTrue($this->seeMembersCount($crawler, 4), 'The adherent should see the members count');
        $this->assertTrue($this->seeHosts($crawler, 2), 'The adherent should see the hosts');
        $this->assertTrue($this->seeHostsContactLink($crawler, 2), 'The adherent should see the hosts contact link');
        $this->assertFalse($this->seeHostNav($crawler), 'The adherent should not see the host navigation');
        $this->assertFalse($this->seeMessageForm($crawler));
    }

    public function testAuthenticatedCommitteeFollowerCanShowCommitteePage()
    {
        $crawler = $this->authenticateAsAdherent($this->client, 'carl999@example.fr', 'secret!12345');
        $crawler = $this->client->click($crawler->selectLink('En Marche Paris 8')->link());

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertFalse($this->seeRegisterLink($crawler, 0), 'The follower should not see the "register link"');
        $this->assertFalse($this->seeFollowLink($crawler), 'The follower should not see the "follow link"');
        $this->assertTrue($this->seeUnfollowLink($crawler), 'The follower should see the "unfollow link"');
        $this->assertTrue($this->seeMembersCount($crawler, 4), 'The follower should see the members count');
        $this->assertTrue($this->seeHosts($crawler, 2), 'The follower should see the hosts');
        $this->assertTrue($this->seeHostsContactLink($crawler, 2), 'The follower should see the hosts contact link');
        $this->assertFalse($this->seeHostNav($crawler), 'The follower should not see the host navigation');
        $this->assertFalse($this->seeMessageForm($crawler));
    }

    public function testAuthenticatedCommitteeHostCanShowCommitteePage()
    {
        $crawler = $this->authenticateAsAdherent($this->client, 'gisele-berthoux@caramail.com', 'ILoveYouManu');
        $crawler = $this->client->click($crawler->selectLink('En Marche Paris 8')->link());

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertFalse($this->seeRegisterLink($crawler, 0), 'The host should not see the "register link"');
        $this->assertFalse($this->seeFollowLink($crawler), 'The host should not see the "follow link"');
        $this->assertTrue($this->seeUnfollowLink($crawler), 'The host should see the "unfollow link" because there is another host');
        $this->assertTrue($this->seeMembersCount($crawler, 4), 'The host should see the members count');
        $this->assertTrue($this->seeHosts($crawler, 2), 'The host should see the hosts');
        $this->assertTrue($this->seeHostsContactLink($crawler, 1), 'The host should see the other contact links');
        $this->assertTrue($this->seeSelfHostContactLink($crawler, 'Gisele Berthoux'), 'The host should see his own contact link');
        $this->assertTrue($this->seeHostNav($crawler), 'The host should see the host navigation');
        $this->assertTrue($this->seeMessageForm($crawler));
    }

    public function testAuthenticatedCommitteeHostCanPostMessages()
    {
        $crawler = $this->authenticateAsAdherent($this->client, 'gisele-berthoux@caramail.com', 'ILoveYouManu');
        $crawler = $this->client->click($crawler->selectLink('En Marche Paris 8')->link());

        $committeeUrl = $this->client->getRequest()->getPathInfo();

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertTrue($this->seeMessageForm($crawler));
        $this->assertFalse($this->seeMessageSuccesfullyCreatedFlash($crawler));
        $this->assertCountTimelineMessages($crawler, 9);

        $crawler = $this->client->submit($crawler->selectButton('committee_feed_message[send]')->form([
            'committee_feed_message' => ['content' => 'yo'],
        ]));

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertTrue($this->seeMessageForm($crawler, ['Le message doit contenir au moins 10 caractères.']));
        $this->assertFalse($this->seeMessageSuccesfullyCreatedFlash($crawler));

        $this->client->submit($crawler->selectButton('committee_feed_message[send]')->form([
            'committee_feed_message' => ['content' => 'Bienvenue !'],
        ]));

        $this->assertClientIsRedirectedTo($committeeUrl, $this->client);

        $crawler = $this->client->followRedirect();

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertTrue($this->seeMessageForm($crawler));
        $this->assertTrue($this->seeMessageSuccesfullyCreatedFlash($crawler, 'Votre message a bien été envoyé.'));
        $this->assertCountTimelineMessages($crawler, 9, 'Message should not be published');

        $message = $this->committeeFeedItemRepository->findMostRecentFeedMessage(LoadAdherentData::COMMITTEE_1_UUID);
        $this->assertInstanceOf(CommitteeFeedItem::class, $message);
        $this->assertSame('Bienvenue !', $message->getContent());

        $this->assertMailCountRecipients(
            $this->getCommitteeSubscribersCount(LoadAdherentData::COMMITTEE_1_UUID),
            CommitteeMessageNotificationMessage::class,
            $message->getUuid()
        );

        $this->client->submit($crawler->selectButton('committee_feed_message[send]')->form([
            'committee_feed_message' => [
                'content' => 'Première publication !',
                'published' => '1',
            ],
        ]));

        $this->assertClientIsRedirectedTo($committeeUrl, $this->client);

        $crawler = $this->client->followRedirect();

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertTrue($this->seeMessageForm($crawler));
        $this->assertTrue($this->seeMessageSuccesfullyCreatedFlash($crawler, 'Votre message a bien été publié.'));
        $this->assertSeeTimelineMessage($crawler, 0, 'Gisele Berthoux', 'Première publication !');
    }

    /**
     * @dataProvider provideFollowerCredentials
     */
    public function testAuthenticatedFollowerCannotSeeCommitteeMembers(string $username, string $password)
    {
        // Authenticate as a committee follower
        $crawler = $this->authenticateAsAdherent($this->client, $username, $password);
        $this->client->click($crawler->selectLink('En Marche Paris 8')->link());
        $this->client->request(Request::METHOD_GET, sprintf('%s/membres', $this->client->getRequest()->getPathInfo()));

        $this->assertResponseStatusCode(Response::HTTP_FORBIDDEN, $this->client->getResponse());
    }

    public function provideFollowerCredentials()
    {
        return [
            'follower 1' => ['carl999@example.fr', 'secret!12345'],
            'follower 2' => ['luciole1989@spambox.fr', 'EnMarche2017'],
        ];
    }

    /**
     * @dataProvider provideHostCredentials
     */
    public function testAuthenticatedHostCanSeeCommitteeMembers(string $username, string $password)
    {
        // Authenticate as the committee supervisor
        $crawler = $this->authenticateAsAdherent($this->client, $username, $password);
        $crawler = $this->client->click($crawler->selectLink('En Marche Paris 8')->link());
        $crawler = $this->client->click($crawler->selectLink('Gérer les adhérents')->link());

        $this->assertTrue($this->seeMembersList($crawler, 5));
        $this->assertSame('Jacques', $crawler->filter('.member-first-name')->eq(2)->text());
        $this->assertSame('P.', $crawler->filter('.member-last-name')->eq(2)->text());
        $this->assertSame('75008', $crawler->filter('.member-postal-code')->eq(2)->text());
        $this->assertSame('Paris 8e', $crawler->filter('.member-city-name')->eq(2)->text());
        $this->assertSame('12/01/2017', $crawler->filter('.member-subscription-date')->eq(2)->text());
    }

    public function provideHostCredentials()
    {
        return [
            'supervisor' => ['jacques.picard@en-marche.fr', 'changeme1337'],
            'host' => ['gisele-berthoux@caramail.com', 'ILoveYouManu'],
        ];
    }

    public function testAuthenticatedCommitteeSupervisorCanPromoteNewHostsAmongMembers()
    {
        // Authenticate as the committee supervisor
        $crawler = $this->authenticateAsAdherent($this->client, 'jacques.picard@en-marche.fr', 'changeme1337');
        $crawler = $this->client->click($crawler->selectLink('En Marche Paris 8')->link());
        $crawler = $this->client->click($crawler->selectLink('Gérer les adhérents')->link());

        $this->assertSame(2, $crawler->filter('.promote-host-link')->count());
        $crawler = $this->client->click($crawler->filter('.promote-host-link')->link());

        $this->client->submit($crawler->selectButton("Oui, promouvoir l'adhérent")->form());
        $crawler = $this->client->followRedirect();

        $this->assertSame(1, $crawler->filter('.promote-host-link')->count());
        $this->assertContains('Le membre a été promu animateur du comité avec succès.', $crawler->filter('#notice-flashes')->text());
    }

    public function testAuthenticatedCommitteeHostCannotPromoteNewHostsAmongMembers()
    {
        // Authenticate as the committee supervisor
        $crawler = $this->authenticateAsAdherent($this->client, 'gisele-berthoux@caramail.com', 'ILoveYouManu');
        $crawler = $this->client->click($crawler->selectLink('En Marche Paris 8')->link());
        $crawler = $this->client->click($crawler->selectLink('Gérer les adhérents')->link());

        $this->assertSame(0, $crawler->filter('.promote-host-link')->count());
    }

    public function testCommitteeExportMembers()
    {
        // Authenticate as the committee supervisor
        $crawler = $this->authenticateAsAdherent($this->client, 'jacques.picard@en-marche.fr', 'changeme1337');
        $crawler = $this->client->click($crawler->selectLink('En Marche Paris 8')->link());
        $crawler = $this->client->click($crawler->selectLink('Gérer les adhérents')->link());

        $token = $crawler->filter('#members-export-token')->attr('value');
        $uuids = (array) $crawler->filter('input[name="members[]"]')->attr('value');

        $exportUrl = $this->client->getRequest()->getPathInfo().'/export';

        $this->client->request(Request::METHOD_POST, $exportUrl, [
            'token' => $token,
            'exports' => json_encode($uuids),
        ]);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertCount(3, explode("\n", $this->client->getResponse()->getContent()));

        // Try to illegally export an adherent data
        $uuids[] = LoadAdherentData::ADHERENT_1_UUID;

        $this->client->request(Request::METHOD_POST, $exportUrl, [
            'token' => $token,
            'exports' => json_encode($uuids),
        ]);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertCount(3, explode("\n", $this->client->getResponse()->getContent()));

        $this->client->request(Request::METHOD_POST, $exportUrl, [
            'token' => $token,
            'exports' => json_encode([]),
        ]);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertCount(2, explode("\n", $this->client->getResponse()->getContent()));
    }

    public function testCommitteeContactMembers()
    {
        // Authenticate as the committee supervisor
        $crawler = $this->authenticateAsAdherent($this->client, 'jacques.picard@en-marche.fr', 'changeme1337');
        $crawler = $this->client->click($crawler->selectLink('En Marche Paris 8')->link());
        $crawler = $this->client->click($crawler->selectLink('Gérer les adhérents')->link());

        $token = $crawler->filter('#members-contact-token')->attr('value');
        $uuids = (array) $crawler->filter('input[name="members[]"]')->attr('value');

        $membersUrl = $this->client->getRequest()->getPathInfo();
        $contactUrl = $membersUrl.'/contact';

        $crawler = $this->client->request(Request::METHOD_POST, $contactUrl, [
            'token' => $token,
            'contacts' => json_encode($uuids),
        ]);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());

        // Try to post with an empty message
        $crawler = $this->client->request(Request::METHOD_POST, $contactUrl, [
            'token' => $crawler->filter('input[name="token"]')->attr('value'),
            'contacts' => $crawler->filter('input[name="contacts"]')->attr('value'),
            'message' => ' ',
        ]);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertSame('Cette valeur ne doit pas être vide.', $crawler->filter('.form__errors > .form__error')->text());

        $this->client->request(Request::METHOD_POST, $contactUrl, [
            'token' => $crawler->filter('input[name="token"]')->attr('value'),
            'contacts' => $crawler->filter('input[name="contacts"]')->attr('value'),
            'message' => 'Hello à tous, j\'espère que vous allez bien!',
        ]);

        $this->assertClientIsRedirectedTo($membersUrl, $this->client);

        $crawler = $this->client->followRedirect();

        $this->seeMessageSuccesfullyCreatedFlash($crawler, 'Félicitations, votre message a bien été envoyé aux membres sélectionnés.');

        // Try to illegally contact an adherent
        $uuids[] = LoadAdherentData::ADHERENT_1_UUID;

        $crawler = $this->client->request(Request::METHOD_POST, $contactUrl, [
            'token' => $token,
            'contacts' => json_encode($uuids),
        ]);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertCount(1, json_decode($crawler->filter('input[name="contacts"]')->attr('value'), true));

        // Force the contact form with foreign uuid
        $this->client->request(Request::METHOD_POST, $contactUrl, [
            'token' => $crawler->filter('input[name="token"]')->attr('value'),
            'contacts' => json_encode($uuids),
            'message' => 'Hello à tous, j\'espère que vous allez bien!',
        ]);

        $this->assertClientIsRedirectedTo($membersUrl, $this->client);
    }

    private function getCommitteeSubscribersCount(string $committeeUuid): int
    {
        return $this
            ->committeeMembershipRepository
            ->findFollowers($committeeUuid)
            ->getCommitteesNotificationsSubscribers()
            ->count();
    }

    private function seeMembersList(Crawler $crawler, int $count): bool
    {
        // Header row is part of the count
        return $count === count($crawler->filter('table > tr'));
    }

    private function seeRegisterLink(Crawler $crawler, $nb = 1): bool
    {
        $this->assertCount($nb, $crawler->filter('.committee-follow--disabled'));

        return 1 === count($crawler->filter('#committee-register-link'));
    }

    private function seeFollowLink(Crawler $crawler): bool
    {
        return 1 === count($crawler->filter('.committee-follow'));
    }

    private function seeUnfollowLink(Crawler $crawler): bool
    {
        return 1 === count($crawler->filter('.committee-unfollow'));
    }

    private function seeMembersCount(Crawler $crawler, string $membersCount): bool
    {
        return $membersCount.' adhérent'.($membersCount > 1 ? 's' : '') === $crawler->filter('.committee__card .committee-members')->text();
    }

    private function seeHosts(Crawler $crawler, int $hostsCount): bool
    {
        return $hostsCount === count($crawler->filter('.committee__card .committee-host'));
    }

    private function seeHostsContactLink(Crawler $crawler, int $hostsCount): bool
    {
        return $hostsCount === count($crawler->filter('.committee__card .committee-host a'));
    }

    private function seeSelfHostContactLink(Crawler $crawler, string $name): bool
    {
        /** @var \DOMElement $host */
        foreach ($crawler->filter('.committee__card .committee-host') as $host) {
            if (false !== strpos($host->textContent, 'Contacter')) {
                continue;
            }

            return preg_match('/'.preg_quote($name).'\s+\(vous\)/', $host->textContent);
        }

        return false;
    }

    private function seeHostNav(Crawler $crawler): bool
    {
        return 1 === count($crawler->filter('#committee-host-nav'));
    }

    private function seeMessageForm(Crawler $crawler, array $errorMessages = []): bool
    {
        if ($errorMessages) {
            $errors = $crawler->filter('form[name="committee_feed_message"] .form__error');

            $this->assertCount(count($errorMessages), $errors);

            foreach ($errorMessages as $i => $errorMessage) {
                $this->assertSame($errorMessage, trim($errors->eq($i)->text()));
            }
        } else {
            $this->assertCount(0, $crawler->filter('form[name="committee_feed_message"] .form__errors'));
        }

        return 1 === count($crawler->filter('form[name="committee_feed_message"]'));
    }

    private function seeMessageSuccesfullyCreatedFlash(Crawler $crawler, ?string $message = null)
    {
        $flash = $crawler->filter('#notice-flashes');

        if ($message) {
            $this->assertSame($message, trim($flash->text()));
        }

        return 1 === count($flash);
    }

    private function assertCountTimelineMessages(Crawler $crawler, int $nb, string $message = '')
    {
        $this->assertSame($nb, $crawler->filter('.committee__timeline__message')->count(), $message);
    }

    private function assertSeeTimelineMessages(Crawler $crawler, array $messages)
    {
        foreach ($messages as $position => $message) {
            list($author, $text) = $message;
            $this->assertSeeTimelineMessage($crawler, $position, $author, $text);
        }
    }

    private function assertSeeTimelineMessage(Crawler $crawler, int $position, string $author, string $text)
    {
        $this->assertSame($author, $crawler->filter('.committee__timeline__message h3')->eq($position)->text());
        $this->assertContains($text, $crawler->filter('.committee__timeline__message div')->eq($position)->text());
    }

    private function assertSeeSocialLinks(Crawler $crawler, Committee $committee)
    {
        $facebookLinkPattern = 'a.committee__social--facebook';
        $googlePlusLinkPattern = 'a.committee__social--google_plus';
        $twitterLinkPattern = 'a.committee__social--twitter';

        if ($facebookUrl = $committee->getFacebookPageUrl()) {
            $this->assertCount(1, $facebookLink = $crawler->filter($facebookLinkPattern));
            $this->assertSame($facebookUrl, $facebookLink->attr('href'));
        } else {
            $this->assertCount(0, $crawler->filter($facebookLinkPattern));
        }

        if ($googlePlusUrl = $committee->getGooglePlusPageUrl()) {
            $this->assertCount(1, $googlePlusLink = $crawler->filter($googlePlusLinkPattern));
            $this->assertSame($googlePlusUrl, $googlePlusLink->attr('href'));
        } else {
            $this->assertCount(0, $crawler->filter($googlePlusLinkPattern));
        }

        if ($twitterNickname = $committee->getTwitterNickname()) {
            $this->assertCount(1, $twitterLink = $crawler->filter($twitterLinkPattern));
            $this->assertSame(sprintf('https://twitter.com/%s', $twitterNickname), $twitterLink->attr('href'));
        } else {
            $this->assertCount(0, $crawler->filter($twitterLinkPattern));
        }
    }

    protected function setUp()
    {
        parent::setUp();

        $this->init([
            LoadAdherentData::class,
            LoadEventCategoryData::class,
            LoadEventData::class,
        ]);

        $this->emailRepository = $this->getMailjetEmailRepository();
        $this->committeeRepository = $this->getCommitteeRepository();
        $this->committeeEventRepository = $this->getEventRepository();
        $this->committeeFeedItemRepository = $this->getCommitteeFeedItemRepository();
        $this->committeeMembershipRepository = $this->getCommitteeMembershipRepository();
    }

    protected function tearDown()
    {
        $this->kill();

        $this->committeeMembershipRepository = null;
        $this->committeeFeedItemRepository = null;
        $this->committeeEventRepository = null;
        $this->committeeRepository = null;
        $this->emailRepository = null;

        parent::tearDown();
    }
}
