# Survey Portal External Module

This module is designed to easily administer a survey many times with a number of customizations.

Example use cases are:

1. Daily diaries for protocol compliance (have you taken your meds, how are you doing, etc..)
1. Sporadic Adverse Event reporting
1. TODO: Enumerate more


## How is this different than an ASI?

* This module allows you to use repeating instances for each occurrence of the survey
* It provides a SINGLE url that is unique for each participant.  This URL **Does not change** for
each repeating survey, so it can be bookmarked on a computer or mobile device for easy re-submission
of the next occurrence.




## How it works

* Each repeating survey is configured via the External Module configuration page.
  * Repeating blocks support multiple survey portals on a given project (for
  example, you could have a portal for a child and one for a parent in the same
  project with different repeating surveys for each group)
* When enabled, the module will create or modify two instruments:

   * **Survey Portal Participant Info** contains details for each participant-configuration.  
   If you enable more than one survey portal configuration in the same project,
   the instrument will be made repeating.

   * Your repeating survey will be modified to contain a number of @HIDDEN-SURVEY fields to store
   metadata relevant to your survey.  These fields are:
     * survey_portal_config_id  (string)
     * survey_portal_day        (integer)
     * survey_portal_date       (date field Y-M-D)
     * survey_portal_launch_ts  (date/time field Y-M-D)

* After completing module setup, project administrators will see a new EM link on the side panel that
can provide information about the setup and status of this module.  If it is RED, please review.

* When a new record is created in the project, upon saving, a unique Survey Portal hash and url will be created.
These values can be seen in the Survey Portal Participant Info form and can be shared with participants
for portal access.

* When a participant visits the Survey Portal link, they will either be directed to a repeating survey or
have the option of viewing a calendar where the participant can select the date for which they are submitting
the survey.  This supports retroactive submission of missed dates if supported by your protocol.

* When a survey is launched, a number of fields are pre-populated in the repeating survey form that track important
context information for the survey, such as day number (relative to a reference date), config, start_time, etc...


## Features and Configuration Options

* Landing page can display either:
  * Calendar where user selects date to submit (the calendar only allows selection of valid dates as configured in setup)
  * Auto-redirect to current survey for this day
* Invitations can be sent to participants using Email or SMS (if Twilio credentials are provided).
  The days in which invitations are set can be customized.




* TODO?

## Setup   


     



