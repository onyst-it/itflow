<?php
/** ONYST ticket email branding and explicit resolution delivery guard. */
function onystUnescapeMailSql($value) {
    return strtr($value, ['\\0'=>chr(0),'\\n'=>chr(10),'\\r'=>chr(13),'\\Z'=>chr(26),'\\\\'=>'\\',"\\'"=>"'",'\\"'=>'"']);
}
function onystStripQuotedTicketEmail($message) {
    return preg_replace('/(?:<(?:span|i)\b[^>]*>\s*)?(?:-{5}\s*REPLY\s+ABOVE\s+THIS\s+LINE\s+TO\s+SEND\s+A\s+RESPONSE\s*-{5}|##-\s*Please\s+type\s+your\s+reply\s+above\s+this\s+line\s*-##).*$/is','',$message);
}
function onystTicketEmailDetails($subject) {
    if (!preg_match('/^Ticket (update|updated|resolved) - \[([^\]]+)\] - (.+?)(?: \| \(pending closure\))?$/is',$subject,$m)) return null;
    return ['label'=>strtolower($m[1])==='resolved'?'Ticket Resolved':'Ticket Updated','number'=>$m[2],'title'=>$m[3]];
}
function onystTicketEmailSubject($subject) {
    $details=onystTicketEmailDetails($subject);
    return $details ? preg_replace('/^Ticket (?:update|updated|resolved)(?= - \[)/i',$details['label'],$subject,1) : $subject;
}
function onystCleanTicketEmailBody($body) {
    $body=preg_replace('/<i\b[^>]*>##-\s*Please\s+type\s+your\s+reply\s+above\s+this\s+line\s*-##<\/i>(?:<br>){0,2}/i','',$body,1);
    return preg_replace('/<br><br>--<br>.*?(?=<br><br>----------------------------------------<br>YOU ARE A COLLABORATOR|$)/s','',$body);
}
function onystTicketMessageHasContent(string $message): bool {
    return trim(str_replace("\xC2\xA0", ' ', html_entity_decode(strip_tags($message), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) !== ''
        || preg_match('/<(?:img|video|audio)\b/i', $message) === 1;
}
function onystRenderTicketMessage(string $label,string $number,string $title,string $message,bool $watcher=false): string {
    if (!in_array($label,['Ticket Updated','Ticket Resolved'],true)) throw new InvalidArgumentException('Unknown ticket email event.');
    if (!onystTicketMessageHasContent($message)) throw new InvalidArgumentException('A ticket email needs an explicit message.');
    $number=htmlspecialchars($number,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $title=htmlspecialchars($title,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $content='<p style="text-align: center;"><strong>'.$label.'</strong> &ndash; Ticket # '.$number.': '.$title.'</p>'
        .'<hr style="margin-bottom: 25px;" />'.$message.'<hr style="margin-top: 25px;margin-bottom: 25px;" />';
    if ($watcher) $content.='<p style="color: #959595;font-size: 11px;">YOU ARE A COLLABORATOR ON THIS TICKET</p>';
    $wrapper=file_get_contents(__DIR__.'/onyst_ticket_email_wrapper.html');
    if ($label==='Ticket Resolved') $wrapper=str_replace('data-onyst-ticket-email="1"','data-onyst-ticket-email="1" data-onyst-resolution-email="1"',$wrapper);
    return str_replace('{{email_body}}',$content,$wrapper);
}
function onystResolutionDeliveryAllowed(string $subject,string $body): bool {
    $details=onystTicketEmailDetails($subject);
    if (!$details || $details['label']!=='Ticket Resolved') return true;
    return strpos($body,'data-onyst-resolution-email="1"')!==false && strpos($body,'<strong>Ticket Resolved</strong>')!==false;
}
/** null means the obsolete/default resolution message was blocked; never fall back to it. */
function onystFormatTicketEmail($body,$subject) {
    $details=onystTicketEmailDetails($subject);
    $resolved=$details && $details['label']==='Ticket Resolved';
    if (strpos($body,'data-onyst-ticket-email="1"')!==false) return (!$resolved || onystResolutionDeliveryAllowed($subject,$body))?$body:null;
    if ($resolved) return null;
    if (strpos($body,'##- Please type your reply above this line -##')===false) return $resolved?null:$body;
    if ($details) {
        $opening='<br><br>--------------------------------<br>';
        $closing='<br>--------------------------------<br><br>';
        $start=strpos($body,$opening);$end=strrpos($body,$closing);
        if ($start!==false && $end!==false && $end>$start) {
            $message=substr($body,$start+strlen($opening),$end-$start-strlen($opening));
            if (!onystTicketMessageHasContent($message)) return $resolved?null:$body;
            return onystRenderTicketMessage($details['label'],$details['number'],$details['title'],$message,strpos($body,'YOU ARE A COLLABORATOR ON THIS TICKET')!==false);
        }
        if ($resolved) return null;
    }
    return str_replace('{{email_body}}',onystCleanTicketEmailBody($body),file_get_contents(__DIR__.'/onyst_ticket_email_wrapper.html'));
}
