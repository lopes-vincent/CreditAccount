<?php
/*************************************************************************************/
/*      This file is part of the Thelia package.                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : dev@thelia.net                                                       */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace CreditAccount\EventListeners;

use CreditAccount\CreditAccount;
use CreditAccount\Event\CreditAccountEvent;
use CreditAccount\Model\CreditAccountExpiration;
use CreditAccount\Model\CreditAccountExpirationQuery;
use CreditAccount\Model\CreditAccountQuery;
use CreditAccount\Model\CreditAccount as CreditAccountModel;
use CreditAccount\Model\CreditAmountHistoryQuery;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Core\Event\Cart\CartEvent;
use Thelia\Core\Event\Coupon\CouponConsumeEvent;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\Translation\Translator;
use Thelia\Model\CustomerQuery;


/**
 * Class CreditEventListener
 * @package CreditAccount\EventListeners
 * @author Manuel Raynaud <mraynaud@openstudio.fr>
 */
class CreditEventListener implements EventSubscriberInterface
{
    const CANCELED = 'canceled';

    /**
     * @var \Thelia\Core\HttpFoundation\Request
     */
    protected $request;

    /**
     * @var \Thelia\Core\Translation\Translator
     */
    protected $translator;

    /**
     * @var EventDispatcherInterface
     */
    protected $dispatcher;

    /**
     * @param Request $request
     * @param Translator $translator
     * @param EventDispatcherInterface $dispatcher
     */
    public function __construct(Request $request, Translator $translator, EventDispatcherInterface $dispatcher)
    {
        $this->request = $request;
        $this->translator = $translator;
        $this->dispatcher = $dispatcher;
    }

    public function addAmount(CreditAccountEvent $event)
    {
        $customer = $event->getCustomer();

        $creditAccount = CreditAccountQuery::create()
            ->filterByCustomerId($customer->getId())
            ->findOne();
        ;

        if (null === $creditAccount) {
            $creditAccount = (new CreditAccountModel())
                ->setCustomerId($customer->getId());
        }

        $creditAccount->addAmount($event->getAmount(), $event->getOrderId(), $event->getWhoDidIt())
            ->save();

        $event->setCreditAccount($creditAccount);
    }

    public function updateOrCreateExpiration(CreditAccountEvent $event)
    {
        if (CreditAccount::getConfigValue('expiration_enabled', false) && $event->getAmount() > 0) {
            $creditAccountExpiration =  CreditAccountExpirationQuery::create()
                ->filterByCreditAccountId($event->getCreditAccount()->getId())
                ->findOneOrCreate();

            $creditAccountExpiration->setExpirationStart(new \DateTime())
                ->setExpirationDelay(CreditAccount::getConfigValue('expiration_delay', 18));

            $creditAccountExpiration->save();
        }
    }

    public function verifyCreditUsage(OrderEvent $event)
    {
        $session = $this->request->getSession();

        if ($session->get('creditAccount.used') == 1) {
            $customer = $event->getOrder()->getCustomer();
            $amount = $session->get('creditAccount.amount');

            $creditEvent = new CreditAccountEvent($customer, ($amount*-1), $event->getOrder()->getId());

            $creditEvent
                ->setWhoDidIt(Translator::getInstance()->trans('Customer', [], CreditAccount::DOMAIN))
                ->setOrderId($event->getOrder()->getId())
            ;

            $event->getDispatcher()->dispatch(CreditAccount::CREDIT_ACCOUNT_ADD_AMOUNT, $creditEvent);

            $session->set('creditAccount.used', 0);
            $session->set('creditAccount.amount', 0);
        }
    }

    public function verifyCoupon(CouponConsumeEvent $event)
    {
        $session = $this->request->getSession();
        if ($session->get('creditAccount.used') == 1) {
            $event->stopPropagation();
            $message = $this->translator->trans("You can't use both coupon and credit", array(), "creditaccount");
            throw new \Exception($message);
        }
    }

    public function updateCreditOnCancel(OrderEvent $event)
    {
        $order = $event->getOrder();
        if ($order->isCancelled()) {
            $haveCredits = CreditAmountHistoryQuery::create()
                ->findByOrderId($order->getId());

            foreach ($haveCredits as $haveCredit) {
                $creditEvent = new CreditAccountEvent($order->getCustomer(), -($haveCredit->getAmount()), $order->getId());
                $event->getDispatcher()->dispatch(CreditAccount::CREDIT_ACCOUNT_ADD_AMOUNT, $creditEvent);
            }
        }
    }

    public function checkCreditExpiration(CartEvent $event)
    {
        $customerId  = $event->getCart()->getCustomerId();

        if (null === $customerId) {
            return;
        }

        /** @var CreditAccountExpiration $creditExpiration */
        $creditExpiration = CreditAccountExpirationQuery::create()
            ->useCreditAccountQuery()
                ->filterByCustomerId($customerId)
            ->endUse()
            ->findOne();

        if (null === $creditExpiration) {
            return;
        }

        $expirationDelay = $creditExpiration->getExpirationDelay();
        $interval = new \DateInterval('P'.$expirationDelay.'M');

        /** @var \DateTime $startDate */
        $startDate = $creditExpiration->getExpirationStart();
        $expirationDate = $startDate->add($interval);

        $now = new \DateTime();

        if ($now > $expirationDate) {
            $customer = CustomerQuery::create()
                ->findOneById($customerId);

            $creditAccount = CreditAccountQuery::create()
                ->findOneByCustomerId($customer->getId());

            $event = new CreditAccountEvent($customer, -$creditAccount->getAmount());
            $event->setWhoDidIt("Expiration $expirationDelay months");
            $this->dispatcher->dispatch(CreditAccount::CREDIT_ACCOUNT_ADD_AMOUNT, $event);

            $creditExpiration->delete();
        }
    }

    public static function getSubscribedEvents()
    {
        return [
            CreditAccount::CREDIT_ACCOUNT_ADD_AMOUNT => [
                    ['addAmount', 128],
                    ['updateOrCreateExpiration', 64],
                ],
            TheliaEvents::ORDER_BEFORE_PAYMENT => ['verifyCreditUsage', 128],
            TheliaEvents::ORDER_UPDATE_STATUS => ['updateCreditOnCancel'],
            TheliaEvents::COUPON_CONSUME => ["verifyCoupon", 140],
            TheliaEvents::CART_ADDITEM => ["checkCreditExpiration", 10]
        ];
    }
}