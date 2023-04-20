<?php
/** CG Exif
 * Version			: 1.0.0
 * Package			: Joomla 4.1
 * copyright 		: Copyright (C) 2023 ConseilGouz. All rights reserved.
 * license    		: http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
 *
 * Fill EXIF custom fields for JPG/TIFF images in phocagallery
 *
 */
namespace ConseilGouz\Plugin\Task\CGExif\Extension;

defined('_JEXEC') or die;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status as TaskStatus;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Event\SubscriberInterface;
use Joomla\Component\Fields\Administrator\Table\FieldTable;
use Joomla\Component\Fields\Administrator\Model\FieldModel;

final class CGExif extends CMSPlugin implements SubscriberInterface {
    use TaskPluginTrait;
    /**
     * @var boolean
     * @since 4.1.0
     */
    protected $autoloadLanguage = true;
    /**
     * @var string[]
     *
     * @since 4.1.0
     */
    protected const TASKS_MAP = [
        'cgexif' => [
            'langConstPrefix' => 'PLG_TASK_CGEXIF',
            'form'            => 'cgexif',
            'method'          => 'cgexif',
        ],
    ];
	protected $myparams;
    /**
     * Start time for the index process
     * @var    string
     */
    private $time;
    
    /**
     * Start time for each batch
     * @var    string
     */
    private $qtime;

    /**
     * Pausing type or defined pause time in seconds.
     * One pausing type is implemented: 'division' for dynamic calculation of pauses
     *
     * Defaults to 'division'
     *
     * @var    string|integer
     */
    private $pause = 1;
    
    /**
     * Minimum processing time in seconds, in order to apply a pause
     * Defaults to 5
     *
     * @var    integer
     */
    private $minimumBatchProcessingTime = 5;
    
    /**
     * default exif_infos.
     * @var    string
     */
    private $exifinfos = 'FILE.FileName,FILE.FileDateTime,FILE.FileSize,FILE.MimeType,COMPUTED.Height,COMPUTED.Width,COMPUTED.IsColor,COMPUTED.ApertureFNumber,IFD0.Make,IFD0.Model,IFD0.Orientation,IFD0.XResolution,IFD0.YResolution,IFD0.ResolutionUnit,IFD0.Software,IFD0.DateTime,IFD0.Exif_IFD_Pointer,IFD0.GPS_IFD_Pointer,EXIF.ExposureTime,EXIF.FNumber,EXIF.ExposureProgram,EXIF.ISOSpeedRatings,EXIF.ExifVersion,EXIF.DateTimeOriginal,EXIF.DateTimeDigitized,EXIF.ShutterSpeedValue,EXIF.ApertureValue,EXIF.ExposureBiasValue,EXIF.MaxApertureValue,EXIF.MeteringMode,EXIF.LightSource,EXIF.Flash,EXIF.FocalLength,EXIF.SubSecTimeOriginal,EXIF.SubSecTimeDigitized,EXIF.ColorSpace,EXIF.ExifImageWidth,EXIF.ExifImageLength,EXIF.SensingMethod,EXIF.CustomRendered,EXIF.ExposureMode,EXIF.WhiteBalance,EXIF.DigitalZoomRatio,EXIF.FocalLengthIn35mmFilm,EXIF.SceneCaptureType,EXIF.GainControl,EXIF.Contrast,EXIF.Saturation,EXIF.Sharpness,EXIF.BrightnessValue,EXIF.SubjectDistanceRange,GPS.GPSLatitudeRef,GPS.GPSLatitude,GPS.GPSLongitudeRef,GPS.GPSLongitude,GPS.GPSAltitudeRef,GPS.GPSAltitude,GPS.GPSTimeStamp,GPS.GPSStatus,GPS.GPSMapDatum,GPS.GPSDateStamp';

    /**
     * Field Context
     *
     * @return string[]
     *
     */
    private $field_context = "com_phocagallery.image";
    /*
     * Field name -> id
     */
    private $fields_per_names = [];
    
    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList'    => 'advertiseRoutines',
            'onExecuteTask'        => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
        ];
    }
    
    private function cgexif(ExecuteTaskEvent $event): int {
       if (!ComponentHelper::isEnabled('com_phocagallery', true)) {
            $this->logTask(Text::_('PLG_TASK_CGEXIF_NO_PHOCA'));
            return TaskStatus::NO_RUN; // Not sure about this exit code....
        }
		if (!function_exists('exif_read_data')) {
		    $this->logTask(Text::_('PLG_TASK_CGEXIF_NO_EXIF_FUNCTION'));
            return TaskStatus::NO_RUN; // Not sure about this exit code....
		}
        $app = Factory::getApplication();
        $this->myparams = $event->getArgument('params');
        $fields = $this->getFields(); // get phocagallery custom fields
        foreach ($fields as $field) { // create fields list per name
            $this->fields_per_names[$field->name] = $field->id;
        }
        $this->go();
        return TaskStatus::OK;
    }
     private function go(): bool {
		
		if (! class_exists('PhocaGalleryLoader')) {
			require_once( JPATH_ADMINISTRATOR.'/components/com_phocagallery/libraries/loader.php');
		}
		require_once JPATH_ADMINISTRATOR . '/components/com_phocagallery/libraries/autoloadPhoca.php';
		phocagalleryimport('phocagallery.path.path');
		phocagalleryimport('phocagallery.file.file');
		$lang = Factory::getLanguage();
		$lang->load('com_phocagallery');
		$images = $this->getImages();
		
		$this->qtime = microtime(true);
		$this->pause = (int)$this->myparams->pause;
		$this->minimumBatchProcessingTime = $this->myparams->time;
		$found = 0;
		$ignored = 0;
		$duration = microtime(true);
		foreach ($images as $image) {
		    $value = '';
            $originalFile = \PhocaGalleryFile::getFileOriginal($image->filename);
            if (!$this->get_exif($image->id,$originalFile)) $ignored++;
		    $processingTime = round(microtime(true) - $this->qtime, 3);
		    $skip  = !($processingTime >= $this->minimumBatchProcessingTime);
		    if ($this->pause > 0 && !$skip) {
		        sleep($this->pause);
		        $this->qtime = microtime(true);
		    }
		    $found++;
		}
		$duration = round(microtime(true) - $duration, 3);
		$this->logTask(sprintf(Text::_('PLG_TASK_CGEXIF_RESULT'),$found,$ignored,$duration));
        return true;
    }
	/* From components/com_phocagallery/views/info/view.html.php */
	private function get_exif($id,$originalFile) {

		$exif = @exif_read_data( $originalFile, 'IFD0');
		if ($exif === false) {
			return false;
		}
		$setExif 		= $this->exifinfos;
		$setExifArray	= explode(",", $setExif, 200);
		$exif 			= @exif_read_data($originalFile, 0, true);
		
        $model = new FieldModel();

        foreach ($setExifArray as $ks => $vs) {
			if ($vs == '') {
				continue;
			}
			$vsValues	= explode(".", $vs, 2);
			if (isset($vsValues[0])) {
				$section = $vsValues[0];
			} else {
				$section = '';
			}
			if (isset($vsValues[1])) {
				$name = $vsValues[1];
			} else {
				$name = '';
			}
			if ($section != '' && $name != '') {
				if (isset($exif[$section][$name])) {
					switch ($name) {
						case 'FileDateTime':
							$exifValue 	= date('d/m/Y, H:m',$exif[$section][$name]);
							break;
						case 'FileSize':
							$exifValue	= \PhocaGalleryFile::getFileSizeReadable($exif[$section][$name]);
							break;
						case 'Height':
						case 'Width':
						case 'ExifImageWidth':
						case 'ExifImageLength':
							$exifValue	= $exif[$section][$name] . ' px';
							break;
						case 'IsColor':
							switch((int)$exif[$section][$name]) {
								case 0:
									$exifValue = Text::_('PLG_TASK_CGEXIF_NO');
									break;
								default:
									$exifValue = Text::_('PLG_TASK_CGEXIF_YES');
								break;
							}
							break;
						case 'ResolutionUnit':
							switch((int)$exif[$section][$name]) {
								case 2:
									$exifValue = Text::_('PLG_TASK_CGEXIF_INCH');
									break;
								case 3:
									$exifValue = Text::_('PLG_TASK_CGEXIF_CM');
									break;
								case 4:
									$exifValue = Text::_('PLG_TASK_CGEXIF_MM');
									break;
								case 5:
									$exifValue = Text::_('PLG_TASK_CGEXIF_MICRO');
									break;
								case 0:
								case 1:
								default:
									$exifValue = '?';
								break;
							}
							break;
						case 'ExposureProgram':
							switch((int)$exif[$section][$name]) {
								case 1:
									$exifValue = Text::_('PLG_TASK_CGEXIF_MANUAL');
									break;
								case 2:
									$exifValue = Text::_('PLG_TASK_CGEXIF_NORMAL_PROGRAM');
									break;
								case 3:
									$exifValue = Text::_('PLG_TASK_CGEXIF_APERTURE_PRIORITY');
									break;
								case 4:
									$exifValue = Text::_('PLG_TASK_CGEXIF_SHUTTER_PRIORITY');
									break;
								case 5:
									$exifValue = Text::_('PLG_TASK_CGEXIF_CREATIVE_PROGRAM');
									break;
								case 6:
									$exifValue = Text::_('PLG_TASK_CGEXIF_ACTION_PROGRAM');
									break;
								case 7:
									$exifValue = Text::_('PLG_TASK_CGEXIF_PORTRAIT_MODE');
									break;
								case 8:
									$exifValue = Text::_('PLG_TASK_CGEXIF_LANDSCAPE_MODE');
									break;
								case 0:
								default:
									$exifValue = Text::_('PLG_TASK_CGEXIF_NOT_DEFINED');
									break;
							}
							break;
						case 'MeteringMode':
							switch((int)$exif[$section][$name]) {
								case 0:
									$exifValue = Text::_('PLG_TASK_CGEXIF_UNKNOWN');
									break;
								case 1:
									$exifValue = Text::_('PLG_TASK_CGEXIF_AVERAGE');
									break;
								case 2:
									$exifValue = Text::_('PLG_TASK_CGEXIF_CENTERWEIGHTEDAVERAGE');
									break;
								case 3:
									$exifValue = Text::_('PLG_TASK_CGEXIF_SPOT');
									break;
								case 4:
									$exifValue = Text::_('PLG_TASK_CGEXIF_MULTISPOT');
									break;
								case 5:
									$exifValue = Text::_('PLG_TASK_CGEXIF_PATTERN');
									break;
								case 6:
									$exifValue = Text::_('PLG_TASK_CGEXIF_PARTIAL');
									break;
								case 255:
								default:
									$exifValue = Text::_('PLG_TASK_CGEXIF_OTHER');
									break;
							}
							break;
						case 'LightSource':
							switch((int)$exif[$section][$name]) {
								case 0:
									$exifValue = Text::_('PLG_TASK_CGEXIF_UNKNOWN');
									break;
								case 1:
									$exifValue = Text::_('PLG_TASK_CGEXIF_DAYLIGHT');
									break;
								case 2:
									$exifValue = Text::_('PLG_TASK_CGEXIF_FLUORESCENT');
									break;
								case 3:
									$exifValue = Text::_('PLG_TASK_CGEXIF_TUNGSTEN');
									break;
								case 4:
									$exifValue = Text::_('PLG_TASK_CGEXIF_FLASH');
									break;
								case 9:
									$exifValue = Text::_('PLG_TASK_CGEXIF_FINEWEATHER');
									break;
								case 10:
									$exifValue = Text::_('PLG_TASK_CGEXIF_CLOUDYWEATHER');
									break;
								case 11:
									$exifValue = Text::_('PLG_TASK_CGEXIF_SHADE');
									break;
								case 12:
									$exifValue = Text::_('PLG_TASK_CGEXIF_DAYLIGHTFLUORESCENT');
									break;
								case 13:
									$exifValue = Text::_('PLG_TASK_CGEXIF_DAYWHITEFLUORESCENT');
									break;
								case 14:
									$exifValue = Text::_('PLG_TASK_CGEXIF_COOLWHITEFLUORESCENT');
									break;
								case 15:
									$exifValue = Text::_('PLG_TASK_CGEXIF_WHITEFLUORESCENT');
									break;
								case 17:
									$exifValue = Text::_('PLG_TASK_CGEXIF_STANDARDLIGHTA');
									break;
								case 18:
									$exifValue = Text::_('PLG_TASK_CGEXIF_STANDARDLIGHTB');
									break;
								case 19:
									$exifValue = Text::_('PLG_TASK_CGEXIF_STANDARDLIGHTC');
									break;
								case 20:
									$exifValue = Text::_('PLG_TASK_CGEXIF_D55');
									break;
								case 21:
									$exifValue = Text::_('PLG_TASK_CGEXIF_D65');
									break;
								case 22:
									$exifValue = Text::_('PLG_TASK_CGEXIF_D75');
									break;
								case 23:
									$exifValue = Text::_('PLG_TASK_CGEXIF_D50');
									break;
								case 24:
									$exifValue = Text::_('PLG_TASK_CGEXIF_ISOSTUDIOTUNGSTEN');
									break;
								case 255:
								default:
									$exifValue = Text::_('PLG_TASK_CGEXIF_OTHERLIGHTSOURCE');
									break;
							}
							break;
						case 'SensingMethod':
							switch((int)$exif[$section][$name]) {
								case 2:
									$exifValue = Text::_('PLG_TASK_CGEXIF_ONE-CHIP_COLOR_AREA_SENSOR');
									break;
								case 3:
									$exifValue = Text::_('PLG_TASK_CGEXIF_TWO-CHIP_COLOR_AREA_SENSOR');
									break;
								case 4:
									$exifValue = Text::_('PLG_TASK_CGEXIF_THREE-CHIP_COLOR_AREA_SENSOR');
									break;
								case 5:
									$exifValue = Text::_('PLG_TASK_CGEXIF_COLOR_SEQUENTIAL_AREA_SENSOR');
									break;
								case 7:
									$exifValue = Text::_('PLG_TASK_CGEXIF_TRILINEAR_SENSOR');
									break;
								case 8:
									$exifValue = Text::_('PLG_TASK_CGEXIF_COLOR_SEQUENTIAL_LINEAR_SENSOR');
									break;
								case 1:
								default:
									$exifValue = Text::_('PLG_TASK_CGEXIF_NOT_DEFINED');
									break;
							}
							break;
						case 'CustomRendered':
							switch((int)$exif[$section][$name]) {
								case 1:
									$exifValue = Text::_('PLG_TASK_CGEXIF_CUSTOM_PROCESS');
									break;
								case 0:
								default:
									$exifValue = Text::_('PLG_TASK_CGEXIF_NORMAL_PROCESS');
									break;
							}
							break;
						case 'ExposureMode':
							switch((int)$exif[$section][$name]) {
								case 1:
									$exifValue = Text::_('PLG_TASK_CGEXIF_MANUAL_EXPOSURE');
									break;
								case 2:
									$exifValue = Text::_('PLG_TASK_CGEXIF_AUTO_BRACKET');
									break;
								case 0:
								default:
									$exifValue = Text::_('PLG_TASK_CGEXIF_AUTO_EXPOSURE');
									break;
							}
							break;
						case 'WhiteBalance':
							switch((int)$exif[$section][$name]) {
								case 1:
									$exifValue = Text::_('PLG_TASK_CGEXIF_MANUAL_WHITE_BALANCE');
									break;
								case 0:
								default:
									$exifValue = Text::_('PLG_TASK_CGEXIF_AUTO_WHITE_BALANCE');
									break;
							}
							break;
						case 'SceneCaptureType':
							switch((int)$exif[$section][$name]) {
								case 1:
									$exifValue = Text::_('PLG_TASK_CGEXIF_LANDSCAPE');
									break;
								case 2:
									$exifValue = Text::_('PLG_TASK_CGEXIF_PORTRAIT');
									break;
								case 3:
									$exifValue = Text::_('PLG_TASK_CGEXIF_NIGHT_SCENE');
									break;
								case 0:
								default:
									$exifValue = Text::_('PLG_TASK_CGEXIF_STANDARD');
									break;
							}
							break;
						case 'GainControl':
							switch((int)$exif[$section][$name]) {
								case 1:
									$exifValue = Text::_('PLG_TASK_CGEXIF_LOW_GAIN_UP');
									break;
								case 2:
									$exifValue = Text::_('PLG_TASK_CGEXIF_HIGH_GAIN_UP');
									break;
								case 3:
									$exifValue = Text::_('PLG_TASK_CGEXIF_LOW_GAIN_UP');
									break;
								case 4:
									$exifValue = Text::_('PLG_TASK_CGEXIF_HIGH_GAIN_UP');
									break;
								case 0:
								default:
									$exifValue = Text::_('PLG_TASK_CGEXIF_NONE');
									break;
							}
							break;
						case 'ColorSpace':
							switch((int)$exif[$section][$name]) {
								case 1:
									$exifValue = Text::_('PLG_TASK_CGEXIF_SRGB');
									break;
								case 'FFFF.H':
									$exifValue = Text::_('PLG_TASK_CGEXIF_UNCALIBRATED');
									break;
								case 0:
								default:
									$exifValue = '-';
									break;
							}
							break;
						case 'Contrast':
						case 'Sharpness':
							switch((int)$exif[$section][$name]) {
								case 1:
									$exifValue = Text::_('PLG_TASK_CGEXIF_SOFT');
									break;
								case 2:
									$exifValue = Text::_('PLG_TASK_CGEXIF_HARD');
									break;
								case 0:
								default:
									$exifValue = Text::_('PLG_TASK_CGEXIF_NORMAL');
									break;
							}
							break;
						case 'Saturation':
							switch((int)$exif[$section][$name]) {
								case 1:
									$exifValue = Text::_('PLG_TASK_CGEXIF_LOW_SATURATION');
									break;
								case 2:
									$exifValue = Text::_('PLG_TASK_CGEXIF_HIGH_SATURATION');
									break;
								case 0:
								default:
									$exifValue = Text::_('PLG_TASK_CGEXIF_NORMAL');
									break;
							}
							break;
						case 'SubjectDistanceRange':
							switch((int)$exif[$section][$name]) {
								case 1:
									$exifValue = Text::_('PLG_TASK_CGEXIF_MACRO');
									break;
								case 2:
									$exifValue = Text::_('PLG_TASK_CGEXIF_CLOSE_VIEW');
									break;
								case 3:
									$exifValue = Text::_('PLG_TASK_CGEXIF_DISTANT_VIEW');
									break;
								case 0:
								default:
									$exifValue = Text::_('PLG_TASK_CGEXIF_UNKNOWN');
									break;
							}
							break;
						case 'GPSLatitude':
						case 'GPSLongitude':
							$exifValue = '';
							if (isset($exif[$section][$name][0])) {
								list($l,$r)	= explode("/",$exif[$section][$name][0]);
								$d			= ($l/$r);
								$exifValue 	.= $d . '&deg; ';
							}
							if (isset($exif[$section][$name][1])) {
								list($l,$r)	= explode("/",$exif[$section][$name][1]);
								$m			= ($l/$r);
								if ($l%$r>0) {
									$sNoInt = ($l/$r);
									$sInt 	= ($l/$r);
									$s 		= ($sNoInt - (int)$sInt)*60;
									$exifValue 	.= (int)$m . '\' ' . $s . '" ';
								} else {
									$exifValue 	.= $m . '\' ';
									if (isset($exif[$section][$name][2])) {
										list($l,$r)	= explode("/",$exif[$section][$name][2]);
										$s			= ($l/$r);
										$exifValue 	.= $s . '" ';
									}
								}
							}
							break;
						case 'GPSTimeStamp':
							$exifValue = '';
							if (isset($exif[$section][$name][0])) {
								list($l,$r)	= explode("/",$exif[$section][$name][0]);
								$h			= ($l/$r);
								$exifValue 	.= $h . ' h ';
							}
							if (isset($exif[$section][$name][1])) {
								list($l,$r)	= explode("/",$exif[$section][$name][1]);
								$m			= ($l/$r);
								$exifValue 	.= $m . ' m ';
							}
							if (isset($exif[$section][$name][2])) {
								list($l,$r)	= explode("/",$exif[$section][$name][2]);
								$s			= ($l/$r);
								$exifValue 	.= $s . ' s ';
							}
							break;
						case 'ExifVersion':
							if (is_numeric($exif[$section][$name])) {
								$exifValue = (int)$exif[$section][$name]/100;
							} else {
								$exifValue = $exif[$section][$name];
							}
							break;
						case 'FocalLength':
							if (isset($exif[$section][$name]) && $exif[$section][$name] != '') {
								$focalLength = explode ('/', $exif[$section][$name]);
								if (isset($focalLength[0]) && (int)$focalLength[0] > 0
								&& isset($focalLength[1]) && (int)$focalLength[1] > 0 ) {
									$exifValue = (int)$focalLength[0] / (int)$focalLength[1];
									$exifValue = $exifValue . ' mm';
								}
							}
							break;
						case 'ExposureTime':
							if (isset($exif[$section][$name]) && $exif[$section][$name] != '') {
								$exposureTime = explode ('/', $exif[$section][$name]);
								if (isset($exposureTime[0]) && (int)$exposureTime[0] > 0
								&& isset($exposureTime[1]) && (int)$exposureTime[1] > 1 ) {
									if ((int)$exposureTime[1] > (int)$exposureTime[0]) {
										$exifValue = (int)$exposureTime[1] / (int)$exposureTime[0];
										$exifValue = '1/'. $exifValue . ' sec';
									}
								}
							}
							break;
						default:
							$exifValue = $exif[$section][$name];
							break;
					} // end of switch name
					$afield = strtolower(str_replace('.', '_', $vs));
					if (!isset($this->fields_per_names[$afield])) {
					    $this->fields_per_names[$afield] = $this->createField($afield);
					}
					$field_id = $this->fields_per_names[$afield];
					$model->setFieldValue($field_id,$id,$exif[$section][$name]);
				}
			}
		} // end of foreach
	}
    /**
     * Get images list with no custom field defined
	 *
     * Note : EXIF infos are only available for jpg/tiff images
     */
    private function getImages()
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query
            ->select('g.id,g.filename')
            ->from($db->qn('#__phocagallery') . ' AS g')
			->join('LEFT',$db->qn('#__fields_values') .' AS v ON g.id = v.item_id') 
            ->where($db->qn('g.published') . ' = 1 AND ' . $db->qn('g.approved').' = 1')
			->where($db->qn('v.item_id').' IS NULL')
			->where('LOWER(RIGHT(g.filename,4)) IN ('.$db->q('.jpg').','.$db->q('jpeg').','.$db->q('tiff').')'); 
        $images = $db->setQuery($query)->loadObjectList();
		return $images;
    }
    /**
     * Get Fields defined for the context.
     *
     */
    private function getFields()
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query
        ->select('f.*')
        ->from($db->qn('#__fields') . ' AS f')
        ->where($db->qn('f.context') . ' = ' . $db->q($this->field_context));
        $fields = $db->setQuery($query)->loadObjectList();
        return $fields;
    }
    /**
     * Check if field is already defined for current image.
     *
     */
    private function checkField($fieldid,$imageid)
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query
        ->select('f.value')
        ->from($db->qn('#__fields_values') . ' AS f')
        ->where($db->qn('f.field_id') . ' = ' . $fieldid . ' AND '.$db->qn('f.item_id') .' = '.$imageid);
        $value = $db->setQuery($query)->loadResult();
        return $value;
    }
	/**
	* Create a custom field if it does not exit yet
	*/
    private function createField($title) {
        $this->db = Factory::getContainer()->get('DatabaseDriver');
        $table = new FieldTable($this->db);
        $name = strtolower($title);
        $table->reset();
        $table->id = 0;
        $table->title   = $title;
        $table->name    = $name;
        $table->label   = Text::_('PLG_TASK_CGEXIF_'.strtoupper($title));
        $table->state = 0;
        $table->context = $this->field_context;
        $table->description = "";
        $table->type = "text";
        $params = ["hint"=>"","class"=>"","label_class"=>"","show_on"=>"","render_class"=>"","showlabel"=>"1","label_render_class"=>"","display"=>"2","layout"=>"","display_readonly"=>"2"];
        $table->params = json_encode($params);
        $params = [];
        $table->fieldparams = "{}";
        $table->language = "*";
        $date = Factory::getDate()->toSql();
        $user = Factory::getUser();
        $table->created_time = $date;
        $table->modified_time = $date;
        $table->created_user_id = $user->get('id');
        $table->modified_by = $user->get('id');
        if (!$table->store()) {
            $err = $table->getError();
            $this->logTask(sprintf(Text::_('PLG_TASK_CGEXIF_ERROR_CREATE'), $table->getError()));
            return false;
        }
        // Get the new item ID
        $newId = $table->get('id');
        return $newId;
    }  
}