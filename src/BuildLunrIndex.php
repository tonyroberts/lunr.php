<?php

namespace LunrPHP;

class BuildLunrIndex {

  private $version = "2.3.6";

  public function __construct() {
    $this->fields = [];
    $this->fieldVectors = [];
    $this->invertedIndex = [];
    $this->pipelines = [];
    $this->ref = "";
    $this->termIndex = 0;
    $this->fieldTermFrequencies = [];
    $this->fieldLengths = [];
    $this->fieldBoosts = [];
    $this->docBoosts = [];
    $this->pipeline = new Pipeline();
    $this->_b = 0.75;
    $this->_k1 = 1.2;
    $this->documentCount = 0;
    $this->averageFieldLength = [];
    $this->precision = 3;
  }

  public function add(array $doc, float $boost=null) {
    $id = $doc[$this->ref];
    $this->documentCount++;

    if (!is_null($boost)) {
        $this->docBoosts[$id] = $boost;
    }

    foreach ($this->fields as $field) {
      if (isset($doc[$field]) && $doc[$field]) {
        $terms = $this->getTerms($doc[$field]);
        $terms = $this->runPipeline($terms);
        $fieldRef = "$field/$id";

        $this->fieldLengths[$fieldRef] = count($terms);
        $fieldTerms = [];
        foreach ($terms as $term) {
          if (!isset($fieldTerms[$term])) {
            $fieldTerms[$term] = 1;
          }
          else {
            $fieldTerms[$term]++;
          }
          $i = $this->getTermInIndex($term, $this->invertedIndex, TRUE);
          // Term exists in index.
          if ($i || $i === 0) {
            $entry = $this->invertedIndex[$i];
            // Field already added to index.
            if (isset($entry[1]->{$field})) {
              $entry[1]->{$field}->{$id} = (object)[];
            }
            // Add field to index.
            else {
              $entry[1]->{$field} = (object)[$id => (object)[]];
            }
            $entry[1]->{$field}->{$id} = (object)[];
            $this->invertedIndex[$i] = $entry;
          }
          // Term needs to be added to the index.
          else {
            $idEntry = (object)[$id => (object)[]];
            $fieldEntry = $this->newFieldEntry($term, $this->fields, $field, $this->termIndex, $id);
            $this->invertedIndex[] = $fieldEntry;
            $this->termIndex++;
          }
        }
        $this->fieldTermFrequencies[$fieldRef] = $fieldTerms;

      }
    }
  }

  /**
   * Creates a new entry. Casts to object in order to create json output in
   * same formats as Lunr.js.
   */
  private function newFieldEntry(string $term, array $fields, string $field, int $termIndex, string $identifier) {
    $fieldEntries = ["_index" => $termIndex];
    foreach ($fields as $fi) {
      if ($field === $fi) {
        $id = (object)[$identifier => (object)[]];
        $fieldEntries[$fi] = $id;
      }
      else {
        $fieldEntries[$fi] = (object)[];
      }
    }
    return [$term, (object)$fieldEntries];
  }

  /**
   * Retrieves term from the inverted index.
   */
  private function getTermInIndex(string $term, $index, $id = FALSE) {
    $i = 0;
    foreach ($index as $entry) {
      if ($term == $entry[0]) {
        if ($id) {
          return $i;
        }
        else {
          return $entry;
        }
      }
      $i++;
    }
    return FALSE;
  }

  public function getTerms($item) {
    $output = [];
    $terms = [];
    if (is_array($item)) {
      $terms = $this->explodeItem($item);
    }
    else {
      $terms = explode(" ", $item);
    }
    foreach ($terms as $term) {
      $output[] = $this->clean($term);
    }
    return $output;
  }

  private function explodeItem($item) {
    foreach($item as $term) {
      $exploded = explode(" ", strtolower($term));
      foreach($exploded as $sub_term) {
        $terms[] = $sub_term;
      }
    }
    return $terms;
  }

  private function clean(string $string) {
    return preg_replace("/[^A-Za-z0-9 ]/", '', $string);
  }

  public function ref($ref) {
    $this->ref = $ref;
  }

  public function field($field, float $boost=null) {
    $this->fields[] = $field;
    if (!is_null($boost)) {
      $this->fieldBoosts[$field] = $boost;
    }
  }

  public function precision($precision) {
    $this->precision = $precision;
  }

  public function addPipeline(string $pipeline) {
    $this->pipelines[] = $pipeline;
  }

  private function runPipeline(array $terms) {
    if ($this->pipelines) {
      foreach($this->pipelines as $indexPipeline) {
        $this->pipeline->add($indexPipeline);
      }
      $terms = $this->pipeline->run($terms);
    }
    return $terms;
  }

  private function calculateAverageFieldLengths() {
    $documentsWithField = [];
    $accumulator = [];
    $totaller = [];
    foreach ($this->fieldLengths as $fieldRef => $fieldLength) {
      $i = explode("/", $fieldRef);
      $field = $i[0];
      $identifier = $i[1];
      isset($documentsWithField[$field]) ? $documentsWithField[$field]++ : $documentsWithField[$field] = 1;
      $accumulator[$field] = isset($accumulator[$field]) ? $accumulator[$field] + $fieldLength : $fieldLength;
    }
    foreach ($this->fields as $field) {
      $totaller[$field] = $accumulator[$field] / $documentsWithField[$field];
    }
    $this->averageFieldLength = $totaller;
  }

  /**
   * Sorts alphabetically. Added as a static function to use usort() in a class.
   */
  private static function sortInvertedIndex($a, $b) {
    return strcmp($a[0], $b[0]);
  }

  private function idf(array $entry, int $documentCount) {
    $documentsWithTerm = 0;
    foreach ($entry[1] as $fieldName => $items) {
      if ($fieldName !== '_index') {
        $documentsWithTerm = $documentsWithTerm + count((array)$items);
      }
    }

    $x = ($documentCount - $documentsWithTerm + 0.5) / ($documentsWithTerm + 0.5);

    return log(1 + abs($x));
  }

  private function createFieldVectors() {
    $fieldVectors = [];

    $termCache = [];
    $precision = pow(10, $this->precision);
    $score = 0;

    foreach ($this->fieldTermFrequencies as $fieldRef => $terms) {
      $fieldLength = $this->fieldLengths[$fieldRef];
      $vector = [$fieldRef, []];
      foreach ($terms as $term => $tf) {
        list($fieldName, $docId) = explode("/", $fieldRef,2);
        $entry = $this->getTermInIndex($term, $this->invertedIndex);
        $termIndex = $entry[1]->{"_index"};
        if (!isset($termCache[$term])) {
          $idf = $this->idf($entry, $this->documentCount);
        }
        else {
          $idf = $termCache[$term];
        }
        $score = $idf * (($this->_k1 + 1) * $tf) / ($this->_k1 * (1 - $this->_b + $this->_b * ($fieldLength / $this->averageFieldLength[$fieldName])) + $tf);

        // Apply boosts
        if (isset($this->fieldBoosts[$fieldName])) {
          $score *= $this->fieldBoosts[$fieldName];
        }
        if (isset($this->docBoosts[$docId])) {
          $score *= $this->docBoosts[$docId];
        }

        $scoreWithPrecision = round($score * $precision) / $precision;
        // Converts 1.23456789 to 1.234.
        // Reducing the precision so that the vectors take up less
        // space when serialised. Doing it now so that they behave
        // the same before and after serialisation.
        array_push($vector[1], $termIndex, $scoreWithPrecision);
      }
      $fieldVectors[] = $vector;

    }
    $this->fieldVectors = $fieldVectors;
  }

  public function output() {
    $output = (object)[];
    $output->version = $this->version;
    $output->fields = $this->fields;

    usort($this->invertedIndex, ['LunrPHP\BuildLunrIndex', 'sortInvertedIndex']);

    $this->calculateAverageFieldLengths();
    $this->createFieldVectors();

    $output->fieldVectors = $this->fieldVectors;
    $output->invertedIndex = $this->invertedIndex;
    $output->pipeline = ["stemmer"];
    return $output;
  }
}
