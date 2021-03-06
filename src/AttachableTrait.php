<?php

/**
 * Part of the Attachfiles package.
 *
 * NOTICE OF LICENSE
 *
 * Licensed under MIT License.
 *
 * This source file is subject to MIT License that is
 * bundled with this package in the LICENSE file.
 *
 * @package    Attachfiles
 * @version    1.0.0
 * @author     AngryDeer
 * @license    MIT
 * @copyright  (c) 2016, DP Studio
 * @link       http://angrydeer.ru
 */

namespace Angrydeer\Attachfiles;

use Illuminate\Database\Eloquent\Builder;
use File;
use Storage;
use Image;

trait AttachableTrait
{

    /**
     * The Eloquent attaches model name.
     *
     * @var string
     */
    protected static $attachesModel = 'Angrydeer\Attachfiles\IlluminateAttach';

    /**
     * The Slug generator method.
     *
     * @var string
     */
    protected static $slugGenerator = 'Illuminate\Support\Str::slug';

    /**
     * {@inheritdoc}
     */
    public static function getAttachesModel()
    {
        return static::$attachesModel;
    }

    /**
     * {@inheritdoc}
     */
    public static function setAttachesModel($model)
    {
        static::$attachesModel = $model;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSlugGenerator()
    {
        return static::$slugGenerator;
    }

    /**
     * {@inheritdoc}
     */
    public static function setSlugGenerator($slugGenerator)
    {
        static::$slugGenerator = $slugGenerator;
    }

    /**
     * {@inheritdoc}
     */
    public function attaches()
    {
        return $this->morphToMany(static::$attachesModel, 'attachable', 'attached', 'attachable_id', 'attach_id');
    }

    /**
     * {@inheritdoc}
     */
    public static function allAttaches()
    {
        $instance = new static;

        return $instance->createAttachesModel()->whereNamespace(
            $instance->getEntityClassName()
        );
    }

    /**
     * {@inheritdoc}
     */
    public static function scopeWhereAttach(Builder $query, $attaches, $type = 'filename')
    {
        $attaches = (new static)->prepareAttaches($attaches);

        foreach ($attaches as $attach) {
            $query->whereHas('attaches', function ($query) use ($type, $attach) {
                $query->where($type, $attach);
            });
        }

        return $query;
    }

    /**
     * {@inheritdoc}
     */
    public static function scopeWithAttach(Builder $query, $attaches, $type = 'filename')
    {
        $attaches = (new static)->prepareAttaches($attaches);

        return $query->whereHas('attaches', function ($query) use ($type, $attaches) {
            $query->whereIn($type, $attaches);
        });
    }

    /**
    * {@inheritdoc}
    */
    public static function scopeWithoutAttach(Builder $query, $attaches, $type = 'filename')
    {
        $attaches = (new static)->prepareAttaches($attaches);

        return $query->whereDoesntHave('attaches', function ($query) use ($type, $attaches) {
            $query->whereIn($type, $attaches);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function attach($attaches)
    {
        foreach ($this->prepareAttaches($attaches) as $attach) {
            $this->addAttach($attach);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function unattach($attaches = null)
    {
        $attaches = $attaches ?: $this->attaches->lists('name')->all();

        foreach ($this->prepareAttaches($attaches) as $attach) {
            $this->removeAttach($attach);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function setAttaches($attaches, $type = 'name')
    {
        // Prepare the attaches
        $attaches = $this->prepareAttaches($attaches);

        // Get the current entity attaches
        $entityAttaches = $this->attaches->lists($type)->all();

        // Prepare the attaches to be added and removed
        $attachesToAdd = array_diff($attaches, $entityAttaches);
        $attachesToDel = array_diff($entityAttaches, $attaches);

        // Detach the attaches
        if (! empty($attachesToDel)) {
            $this->unattach($attachesToDel);
        }

        // Attach the attaches
        if (! empty($attachesToAdd)) {
            $this->attach($attachesToAdd);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function addAttach($filename, $title = null, $alt = null, $description = null, $priority = 0)
    {
        $path='attaches/'.date ('Y-m-d').'/';

        $attach = $this->createAttachesModel()->firstOrNew([
            'filename'      => $filename,
            'title' => $title,
            'alt' => $alt,
            'desc' => $description,
            'namespace' => $this->getEntityClassName(),
            'priority' => $priority
        ]);

        if (!$attach->exists) {
            $info = pathinfo($filename);
            
            $file = Image::make(public_path($filename))
                ->resize(2000, null, function ($constraint) {
                    $constraint->aspectRatio();
                })
                ->save()
                ;

            $newFileName = md5(date('Y-m-d-s').$filename).'.'.$info['extension'];
            
            $attach->filename = $path.$newFileName;
            
            $attach->priority = $priority;
            
            Storage::put(
                $path.$newFileName,
                file_get_contents(public_path($filename))
            );
            
            $attach->size = $file->width() . 'x' . $file->height();
            
            $attach->save();
            
            unlink(public_path($filename));
        }

        if (!$this->attaches->contains($attach->id)) {
            $this->attaches()->attach($attach);
        }
        
        return $attach->id;
    }


    /**
     * {@inheritdoc}
     */
    public function updateOrNewAttach($filename, $title = null, $alt = null, $description = null, $priority = 0)
    {
        $id = null;
        

        if(Storage::exists($filename)){
            
            $namespace = $this->getEntityClassName();

            $attach = $this
                ->createAttachesModel()
                ->whereNamespace($namespace)
                ->where(function ($query) use ($filename) {
                    $query
                        ->orWhere('filename', $filename)
                    ;
                })
                ->first()
            ;

            if ($attach) {
                $attach->priority = $priority;
                $attach->title = $title;
                $attach->alt = $alt;
                $attach->desc = $description;
                $attach->save();
                
                $id = $attach->id;
            }
        }
        else {
            
            $id = $this->addAttach($filename, $title, $alt, $description, $priority);
        }
        
        return $id;
    }

    
    /**
     * Add crop data to attach
     */
    public function setAttachCropData($filename, $cropdata)
    {
        $id = null;
        
        if(Storage::exists($filename)){

            $namespace = $this->getEntityClassName();

            $attach = $this
                ->createAttachesModel()
                ->whereNamespace($namespace)
                ->where('filename', $filename)
                ->first()
                ;

            if ($attach) {
                
                $attach->crop = is_array($cropdata) ? $cropdata : json_decode($cropdata);
                $attach->save();

                $id = $attach->id;
            }
        }
        
        return $id;
    }

    

    /**
     * {@inheritdoc}
     */
    public function keepOnly($filenames)
    {
        $collection = collect($filenames);
        foreach($this->attaches as $attach)
        {
            if(! $collection->contains($attach->filename))
            {
                if (Storage::exists($attach->filename)) Storage::delete($attach->filename);
                $this->removeAttach($attach->filename);
            }
        }
    }


    /**
     * {@inheritdoc}
     */
    public function removeAttach($name)
    {
        $namespace = $this->getEntityClassName();

        $attach = $this
            ->createAttachesModel()
            ->whereNamespace($namespace)
            ->where('filename', $name)
            ->first()
        ;
        if ($attach) {
            $this->attaches()->detach($attach);
            
            if (Storage::exists($attach->filename)) Storage::delete($attach->filename);
            
            $attach->delete();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function prepareAttaches($attaches)
    {
        if (is_null($attaches)) {
            return [];
        }

        if (is_string($attaches)) {
            $delimiter = preg_quote($this->getAttachesDelimiter(), '#');

            $attaches = array_map('trim',
                preg_split("#[{$delimiter}]#", $attaches, null, PREG_SPLIT_NO_EMPTY)
            );
        }

        return array_unique(array_filter($attaches));
    }

    /**
     * {@inheritdoc}
     */
    public static function createAttachesModel()
    {
        return new static::$attachesModel;
    }

    /**
     * Generate the attach slug using the given name.
     *
     * @param  string  $name
     * @return string
     */
    protected function generateAttachSlug($name)
    {
        return call_user_func(static::$slugGenerator, $name);
    }

    /**
     * Returns the entity class name.
     *
     * @return string
     */
    protected function getEntityClassName()
    {
        if (isset(static::$entityNamespace)) {
            return static::$entityNamespace;
        }

        return $this->attaches()->getMorphClass();
    }
}
