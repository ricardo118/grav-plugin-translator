# v1.1.1
##  23/01/2020

1. [](#improved)
    * added lang remap config support for google translate
    * added onBeforeGoogleTranslate Event so plugins can override the method for their own custom handling

# v1.1.0
##  23/01/2020

1. [](#new)
    * ADDED GOOGLE TRANSLATE AUTO TRANSLATE SUPPORT!
    * added a screenshot
    * added a preview original language link
    * added support for ckeditor5 plugin
    * added simple keep alive function
    * added `{{ isPreview }}` twig variable to help theme makers
    * new function `superMerge` allows for merging as many pages as needed in order via an array `$wip_page->header($this->mergeHeaders($wip_page, [$english_page, $live_page]));`

2. [](#improved)
    * oninput js functions to only trigger on the translated side
    * improved PHPdocs
    * improved type: list clarity using a new custom form field

3. [](#bugfix)
    * Dirty form js fix
    * Fix the merging of header variables to the correct priority order
    * Minor `type: list` fixes
    * Fix js change language button
    * Fix saving bug for saving lists only partially
    * fix for multiple saves of the same page overriding the previous save

# v1.0.1
##  02/09/2019

1. [](#bugfix)
    * README fix

# v1.0.0
##  26/08/2019

1. [](#new)
    * ChangeLog started...
