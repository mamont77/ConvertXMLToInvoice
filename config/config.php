<?php

return [
  'subject' => [
    'prefix' => '[Send XML to Zoho Books API]',
  ],
  'emails' => [
    'to' => 'ruslan.piskarev@gmail.com',
    'from' => 'ruslan.piskarev@gmail.com',
  ],
  'sender' => [
    'name' => 'Ruslan P',
  ],
  'messages' => [
    'error' => 'There was an error sending, please try again later. <a href="/">Try again</a>.',
    'success' => 'Your message has been sent successfully. <a href="/">Send new file</a>.',
  ],
  'fields' => [
    'name' => 'Name',
    'email' => 'Email',
    'phone' => 'Phone',
    'subject' => 'Subject',
    'message' => 'Message',
    'attachment' => 'XML Attachment',
    'btn-send' => 'Send',
  ],
  'zoho' => [
    'authtoken' => '',
    'organizationID' => '',
  ],
];
