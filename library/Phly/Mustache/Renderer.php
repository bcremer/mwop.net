<?php

namespace Phly\Mustache;

class Renderer
{
    /**
     * @var Mustache
     */
    protected $manager;

    /** @var array Array of registered pragmas */
    protected $pragmas = array();

    /** @var Closure Callback for escaping variable content */
    protected $escaper;

    /**
     * Set mustache manager
     *
     * Used internally to resolve and tokenize partials
     * 
     * @param  Mustache $manager 
     * @return Lexer
     */
    public function setManager(Mustache $manager)
    {
        $this->manager = $manager;
        return $this;
    }

    /**
     * Retrieve the mustache manager
     * 
     * @return null|Mustache
     */
    public function getManager()
    {
        return $this->manager;
    }

    /**
     * Render a set of tokens with view substitutions
     * 
     * @param  array $tokens 
     * @param  mixed $view 
     * @return string
     */
    public function render(array $tokens, $view, array $partials = null)
    {
        // Do some pre-initialization of variables used later in the routine
        $renderer = $this;
        $pragmas  = array();
        $inLoop   = false;

        if (is_object($view)) {
            // If we have an object, get a list of properties and methods, 
            // giving methods precedence.
            $props = get_object_vars($view);
            foreach (get_class_methods($view) as $method) {
                if ('__' == substr($method, 0, 2)) {
                    // Omit magic methods
                    continue;
                }
                $props[$method] = array($view, $method);
            }
            $view = $props;
        }
        if (is_scalar($view)) {
            // Iteration over lists will sometimes involve scalars
            $inLoop = true;
        }

        if (null === $partials) {
            $partials = array();
        }

        $rendered = '';
        foreach ($tokens as $token) {
            list($type, $data) = $token;
            if ($value = $this->handlePragmas($type, $data, $view, $pragmas)) {
                $rendered .= $value;
                continue;
            }
            switch ($type) {
                case Lexer::TOKEN_CONTENT:
                    $rendered .= $data;
                    break;
                case Lexer::TOKEN_VARIABLE:
                    $value = $this->getValue($data, $view);
                    $value = ('' === $value) ? '' : $this->escape($value);
                    $rendered .= $value;
                    break;
                case Lexer::TOKEN_VARIABLE_RAW:
                    $value = $this->getValue($data, $view);
                    $rendered .= $value;
                    break;
                case Lexer::TOKEN_SECTION:
                    if ($inLoop) {
                        // In a loop, with scalar values; skip
                        break;
                    }
                    $section = $this->getValue($data['name'], $view);
                    if (!$section) {
                        // Section is not a true value; skip
                        break;
                    }

                    // Build the section view
                    $sectionView = $section;
                    if (is_bool($section)) {
                        // For a boolean true, pass the current view
                        $sectionView = $view;
                    }
                    if (is_array($section) || $section instanceof \Traversable) {
                        if (is_array($section) && $this->isAssocArray($section)) {
                            // Nested view; pass it as the view
                            $sectionView = $section;
                        } else {
                            // Iteration
                            $renderedSection = '';
                            foreach ($section as $sectionView) {
                                $renderedSection .= $this->render($data['content'], $sectionView);
                            }
                            $rendered .= $renderedSection;
                            break;
                        }
                    } elseif (is_callable($section)) {
                        // Higher order section
                        // Execute the callback, passing it the section's template 
                        // string, as well as a renderer lambda.
                        $rendered .= call_user_func($section, $data['template'], function($text) use ($renderer, $view, $partials) {
                            $manager = $renderer->getManager();
                            if (!$manager instanceof Mustache) {
                                return $text;
                            }
                            $tokens = $manager->tokenize($text);
                            return $renderer->render($tokens, $view, $partials);
                        });
                        break;
                    } elseif (is_object($section)) {
                        // In this case, the child object is the view.
                        $sectionView = $section;
                    } else {
                        // All other types, simply pass the current view
                        $sectionView = $view;
                    }

                    // Render the section
                    $rendered .= $this->render($data['content'], $sectionView);
                    break;
                case Lexer::TOKEN_SECTION_INVERT:
                    if ($inLoop) {
                        // In a loop, with scalar values; skip
                        break;
                    }
                    $section = $this->getValue($data['name'], $view);
                    if ($section) {
                        // If a value exists for the section, we skip it
                        $rendered .= '';
                        break;
                    }

                    // Otherwise, we render it
                    $rendered .= $this->render($data['content'], $view);
                    break;
                case Lexer::TOKEN_PARTIAL:
                    if ($inLoop) {
                        // In a loop, with scalar values; skip
                        break;
                    }
                    if (!isset($data['tokens'])) {
                        // Check to see if the partial invoked is an aliased partial
                        $name = $data['partial'];
                        if (isset($partials[$data['partial']])) {
                            $rendered .= $this->render($partials[$data['partial']], $view);
                        }
                        break;
                    }
                    $rendered .= $this->render($data['tokens'], $view);
                    break;
                case Lexer::TOKEN_PRAGMA:
                    $this->registerPragma($data, $pragmas);
                    break;
                case Lexer::TOKEN_DELIM_SET:
                case Lexer::COMMENT:
                default:
                    // do nothing; only necessary for tokenization/parsing
                    break;
            }
        }
        return $rendered;
    }

    /**
     * escape 
     * 
     * @todo   allow using a callback for escaping
     * @param  string $value 
     * @return string
     */
    public function escape($value)
    {
        $escaper = $this->getEscaper();
        return call_user_func($escaper, $value);
    }

    /**
     * Set escaping mechanism
     * 
     * @param  callback $callback 
     * @return Renderer
     */
    public function setEscaper($callback)
    {
        if (!is_callable($callback)) {
            throw new InvalidEscaperException();
        }
        $this->escaper = $callback;
        return $this;
    }

    public function getEscaper()
    {
        if (null === $this->escaper) {
            $this->escaper = function($value) {
                return htmlspecialchars((string) $value, ENT_COMPAT, 'UTF-8');
            };
        }
        return $this->escaper;
    }

    /**
     * Add a pragma
     *
     * Pragmas allow extension of mustache capabilities.
     * 
     * @param  Pragma $pragma 
     * @return Renderer
     */
    public function addPragma(Pragma $pragma)
    {
        $this->pragmas[$pragma->getName()] = $pragma;
        return $this;
    }

    /**
     * Retrieve all registered pragmas
     * 
     * @return array
     */
    public function getPragmas()
    {
        return $this->pragmas;
    }

    /**
     * Do we have a pragma by a specified name?
     * 
     * @param  string $name 
     * @return bool
     */
    public function hasPragma($name)
    {
        return isset($this->pragmas[(string) $name]);
    }

    /**
     * Get a registered pragma by name
     * 
     * @param  string $name 
     * @return null|Pragma
     */
    public function getPragma($name)
    {
        $name = (string) $name;
        if ($this->hasPragma($name)) {
            return $this->pragmas[$name];
        }
        return null;
    }

    /**
     * Remove a given pragma
     * 
     * @param  string $name 
     * @return bool Returns true if found and removed; false otherwise
     */
    public function removePragma($name)
    {
        if ($this->hasPragma($name)) {
            unset($this->pragma[$name]);
            return true;
        }
        return false;
    }

    /**
     * Get a named value from the view
     * 
     * Returns an empty string if no matching value found.
     *
     * @param  string $key 
     * @param  mixed $view 
     * @return mixed
     */
    protected function getValue($key, $view)
    {
        if (is_scalar($view)) {
            return $view;
        }
        if (is_object($view)) {
            if (isset($view->$key)) {
                if (is_callable($view->$key)) {
                    return call_user_func($view->$key);
                }
            }
            return '';
        }
        if (isset($view[$key])) {
            if (is_callable($view[$key])) {
                return call_user_func($view[$key]);
            }
            return $view[$key];
        } 
        return '';
    }

    /**
     * Determine if an array is associative
     * 
     * @param  array $array 
     * @return bool
     */
    protected function isAssocArray(array $array)
    {
        return (is_array($array) 
            && (count($array) == 0 
                || 0 !== count(array_diff_key($array, array_keys(array_keys($array))))
            )
        );
    }

    /**
     * Register a pragma for the current rendering session
     * 
     * @param  array $definition 
     * @param  array $pragmas
     * @return void
     */
    protected function registerPragma(array $definition, array &$pragmas)
    {
        $name = $definition['pragma'];
        if (!$this->hasPragma($name)) {
            throw new UnregisteredPragmaException('No handler for pragma "' . $name . '" registered; cannot proceed rendering');
        }
        $pragmas[$name] = $definition['options'];
    }

    /**
     * Handle pragmas
     *
     * Extend the functionality of the renderer via pragmas. When creating new
     * pragmas, extend the appropriate method for the token types affected. 
     * Returning an empty value indicates that the renderer should render normally.
     *
     * This implementation includes the IMPLICIT-ITERATOR pragma, which affects 
     * values within loops.
     * 
     * @param  string $token 
     * @param  mixed $data 
     * @param  mixed $view 
     * @param  array $pragmas 
     * @return mixed
     */
    protected function handlePragma($token, $data, $view, $pragmas)
    {
        foreach ($pragmas as $name => $options) {
            if (null !== ($handler = $this->getPragma($name))) {
                if ($handler->handlesToken($token)) {
                    if ($value = $handler->handle($token, $data, $view, $options)) {
                        return $value;
                    }
                }
            }
        }
    }
}
