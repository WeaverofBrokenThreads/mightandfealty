<?php

namespace BM2\SiteBundle\Command;

use BM2\SiteBundle\Service\History;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


class PaymentCommand extends ContainerAwareCommand {

	private $inactivityDays = 21;

	protected function configure() {
		$this
			->setName('maf:payment:cycle')
			->setDescription('Run payment cycle')
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$output->writeln('maintenance and payment cycle:');
		$em = $this->getContainer()->get('doctrine')->getManager();
		$history = $this->getContainer()->get('history');
		$rm = $this->getContainer()->get('realm_manager');

		$inactives = 0;
		$query = $em->createQuery('SELECT c FROM BM2SiteBundle:Character c WHERE c.slumbering = false AND c.alive = true AND DATE_PART(\'day\', :now - c.last_access) > :inactivity');
		$query->setParameters(array('now'=>new \DateTime("now"), 'inactivity'=>$this->inactivityDays));
		foreach ($query->getResult() as $char) {
			$inactives++;
			$char->setSlumbering(true);

			// check for positions and other cleanup actions
			foreach ($char->getPositions() as $position) {
				// TODO - this could be made much nicer, but for now it should do
				$history->logEvent(
					$position->getRealm(),
					'event.character.inactive.position',
					array('%link-character%'=>$char->getId(), '%link-realmposition%'=>$position->getId()),
					History::MEDIUM, false, 20
				);
				if ($position->getRuler()) {
					$rm->abdicate($position->getRealm(), $char, $char->getSuccessor());					
				}
			}

			// notifications
			foreach ($char->getPrisoners() as $prisoner) {
				$history->logEvent(
					$prisoner,
					'event.character.inactive.prisoner',
					array('%link-character%'=>$char->getId()),
					History::HIGH, false, 20
				);
			}
			if ($char->getLiege()) {
				$history->logEvent(
					$char->getLiege(),
					'event.character.inactive.vassal',
					array('%link-character%'=>$char->getId()),
					History::MEDIUM, false, 20
				);
			}
			foreach ($char->getVassals() as $vassal) {
				$history->logEvent(
					$vassal,
					'event.character.inactive.liege',
					array('%link-character%'=>$char->getId()),
					History::MEDIUM, false, 20
				);
			}
			foreach ($char->getPartnerships() as $partnership) {
				$history->logEvent(
					$partnership->getOtherPartner($char),
					'event.character.inactive.partner',
					array('%link-character%'=>$char->getId()),
					History::MEDIUM, false, 20
				);
			}
		}
		$output->writeln("$inactives characters set to inactive");
		$em->flush();

		// flush the mail queue (account expired messages)
		$mailer = $this->getContainer()->get('mailer');
		$spool = $mailer->getTransport()->getSpool();
		$transport = $this->getContainer()->get('swiftmailer.transport.real');
		if ($spool && $transport) {
			$spool->flushQueue($transport);
		}


		$pm = $this->getContainer()->get('payment_manager');
		list($free, $active, $credits, $expired, $storage) = $pm->paymentCycle();
		$output->writeln("$free free accounts");
		$output->writeln("$storage accounts moved into storage");
		$output->writeln("$credits credits collected from $active users");
		$output->writeln("$expired accounts with insufficient credits");

		return true;
	}


}