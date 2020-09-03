<?php

namespace App\Http\Controllers\Application\Election;

use App\Exceptions\VotingHasNotStarted;
use App\Service;
use App\Exceptions\LogicException;
use Illuminate\Http;

class Ballot extends Base
{
    private $org_id = 'cikrf';
    private $form_id = 'golosovanie';

    public $processDuration;

    public function __construct(Http\Request $request, Http\Response $response, Service\Mgik $mgikService)
    {
        $this->processDuration = Service\ProcessDurationLogger::start('all ballot controller');
        parent::__construct($request, $response, $mgikService, $this->org_id, $this->form_id);
        $this->_assignBallotVars();
    }

    public function show()
    {
        if ($redirection = $this->_checkFormBasicAuth($this->_org_id, $this->_form_id)) {
            return $redirection;
        }
        $template = 'show';
        $client = $this->_client;

        if (empty($client['TELEPHONE'])) {
            $this->_addTemplateVar('message', 'Уважаемый пользователь! Для продолжения работы Вам необходимо перейти 
           на <a href="'.\params::$nlk.'/my/#/settings/profile" target="_blank">страницу "Мои документы"</a> Вашего личного кабинета и подтвердить Ваш номер мобильного телефона. Затем Вы сможете вернуться и продолжить работу.');
            return $this->_renderExceptionError();
        }

        $checkBallotProcess = Service\ProcessDurationLogger::start('Check ballot');
        // TODO: убрать тестового пользователя
        try {
            $userData = $this->_mgikService->checkBallot($this->_client['SSO_ID'], $this->_config->get('debug', false));
            $this->_addTemplateVar('district', $userData['district'] ?? 1);
        } catch (LogicException $e) {
            $this->logError($e);
            $this->_addTemplateVar('message', $e->getMessage());
            return $this->_renderExceptionError();
        } catch (VotingHasNotStarted $exception) {
            return $this->_renderVotingHasNotStartedError($exception);
        }
        Service\ProcessDurationLogger::finish($checkBallotProcess);

        if (!$userData) {
            return $this->_renderUserError();
        }
        $armLoggerProcess = Service\ProcessDurationLogger::start('arm send log');
        $this->_armMgikLogger->info('Открыта форма подтверждения участия', ['action' => 'form_open', 'voitingId' => $this->_votingId, 'sessId' => session_id(), 'ssoId' => $this->_client['SSO_ID']]);
        Service\ProcessDurationLogger::finish($armLoggerProcess);
        $rendered = $this->_renderApplicationView($template,$userData);
        Service\ProcessDurationLogger::finish($this->processDuration);
        return $rendered;
    }

    public function denyLegal()
    {
        return $this->_renderView('base.form_not_for_legal');
    }

    public function submit()
    {
        try {
            $this->isConfirmed();
            $this->_armMgikLogger->info('Попытка получить бюллетень', ['action' => 'form_sent', 'voitingId' => $this->_votingId, 'sessId' => session_id(), 'ssoId' => $this->_client['SSO_ID']]);

            $checkBallotProcess = Service\ProcessDurationLogger::start('Check ballot');
            // TODO: убрать тестового пользователя
            $userData = $this->_mgikService->checkBallot($this->_client['SSO_ID'], $this->_config->get('debug', false));
            Service\ProcessDurationLogger::finish($checkBallotProcess);
            if (!$userData) {
                return $this->_renderUserError();
            }
            $userData['id'] = $this->_votingId; //проставим id голосования

            $process = Service\ProcessDurationLogger::start('Get guid request');
            $guid = $this->_mgikService->getGuid($userData);
            Service\ProcessDurationLogger::finish($process);
            $this->_armMgikLogger->info('Получили шифр бюллетеня', ['action' => 'form_guid', 'ssoId' => $this->_client['SSO_ID'], 'voitingId' => $this->_votingId, 'sessId' => session_id()]);
            $host = $this->_config->get('service/election/host');

            // TODO: убрать тестового пользователя
            $ballotProcess = Service\ProcessDurationLogger::start('Get ballot request');
            $userData = $this->_mgikService->getBallot($this->_client['SSO_ID'], $this->_config->get('debug', false));
            Service\ProcessDurationLogger::finish($ballotProcess);
            if (!$userData) {
                return $this->_renderUserError();
            }
            $this->_armMgikLogger->info('Пометили в мдм получение бюллетеня', ['action' => 'form_redirected', 'voitingId' => $this->_votingId, 'sessId' => session_id(), 'ssoId' => $this->_client['SSO_ID']]);

            return redirect("$host/election/check/$guid");
        } catch (LogicException $e) {
            $this->logError($e);
            $this->_addTemplateVar('message', $e->getMessage());
            return $this->_renderExceptionError();
        } catch (VotingHasNotStarted $exception) {
            return $this->_renderVotingHasNotStartedError($exception);
        } catch (\Exception $e) {
            $this->logError($e);
            $this->_addTemplateVar('message', 'Неизвестная ошибка.');
            return $this->_renderExceptionError();
        }
    }

    protected function _assignBallotVars(): void
    {
        $this->_addTemplateVar('app', []);
        $this->_addTemplateVar('client', $this->_getClient());
        $this->_addTemplateVar('hide_cutalog_button', true);
        $this->_addTemplateVar('about', $this->_config->get($this->_org_id.'/'.$this->_form_id.'/about'));
        $this->_addTemplateVar('rules', $this->_config->get($this->_org_id.'/'.$this->_form_id.'/rules'));
        $this->_addTemplateVar('agreement', $this->_config->get($this->_org_id.'/'.$this->_form_id.'/agreement'));
        $this->_addTemplateVar('buttonVote', $this->_config->get($this->_org_id.'/'.$this->_form_id.'/buttonVote'));
        $this->_addTemplateVar('errorUserMessage', $this->_config->get($this->_org_id.'/'.$this->_form_id.'/errorUserMessage'));
        $this->_addTemplateVar('messageVoteStart', $this->_config->get($this->_org_id.'/'.$this->_form_id.'/messageVoteStart'));
        $this->_addTemplateVar('manualTxt', $this->_config->get($this->_org_id.'/'.$this->_form_id.'/manualTxt'));
        $this->_addTemplateVar('menu', \lib::generate_menu(''));
        $this->_addTemplateVar('form_name', $this->_config->get($this->_org_id.'/'.$this->_form_id.'/formName'));
        $this->_addTemplateVar('confirmType', $this->_getConfirmType());
        $this->_addTemplateVar('enableEmailConfirm', env('ENABLE_MAIL_CONFIRM', true));
    }

    private function _getConfirmType(): string {
        $default = 'sms';
        if (!env('ENABLE_MAIL_CONFIRM', true) || !$this->_client['EMAIL']) {
            return $default;
        }
        $confirmType = app()['session.store']->get('confirmType');
        return $confirmType ?: $default;
    }

    private function _renderExceptionError() {
        $this->_addTemplateVar('show_error', true);
        return $this->_renderApplicationView('error_exception');
    }

    private function _renderUserError() {
        $this->_addTemplateVar('show_error', false);
        return $this->_renderApplicationView('error_user');
    }

    private function _renderVotingHasNotStartedError(VotingHasNotStarted $exception) {
        $voteStartDate = $exception->getVoteStartDate();
        $mdmConfig = Service\Config\PoolConfig::me()->get('Mdm');
        $ruMonth = $this->_ruMonthMap[$voteStartDate->format('m')];
        $formattedDate = "{$voteStartDate->format('d')} {$ruMonth} {$voteStartDate->format('Y')}";
        $formattedTime = $voteStartDate->format('H:i');
        $moscowTimeZone = new \DateTimeZone('+0300');
        $dateEnd = env('VOTE_DATE_END_MSK', '2020-06-20 20:20');
        $voteEndDateTime = new \DateTime($dateEnd, $moscowTimeZone);
        $this->_addTemplateVar('endTime', $voteEndDateTime->format('H:i'));
        $this->_addTemplateVar('endMonth', $this->_ruMonthMap[$voteEndDateTime->format('m')]);
        $this->_addTemplateVar('endDate', $voteEndDateTime->format('d'));
        $this->_addTemplateVar('date', $formattedDate);
        $this->_addTemplateVar('time', $formattedTime);
        $this->_addTemplateVar('base_template_path',  resource_path() . '/views/base');
        $this->_addTemplateVar('retry_time_in_seconds', config('ThrottleRequests')['retryIn']);
        $this->_addTemplateVar('app', []);
        $this->_addTemplateVar('hide_right_block', true);
        $this->_addTemplateVar('show_error', false);
        $this->_addTemplateVar('base.innerMos', ['content' => view('base.common.error.highload')]);
        $this->_addTemplateVar('title_parent_case', $mdmConfig->get("districts/{$exception->getDistrict()}/title_parent_case"));
        return $this->_renderApplicationView('error_voting_not_started');
    }

    private function isConfirmed()
    {
        $type = $this->_getConfirmType();
        $key = "{$type}|confirmed|".$this->_client['SSO_ID'];
        $result = Service\Cache::get($key);
        if ((bool)$result !== true) {
            throw new LogicException('Телефон\почта не был подтвержден', [
            'errorMessage' => 'В кеше отсутствует запись о подтверждении телефона\почты'
            ]);
        }
        return $result;
    }

    protected function _getViewPathPrefix(): string
    {
        return parent::_getViewPathPrefix().'.ballot';
    }
}