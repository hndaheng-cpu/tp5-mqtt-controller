<?php
namespace think\mqtt;

class Parser
{
    public function parse(string $topic, string $payload, array $topicMap, string $defaultTable)
    {
        $data = [
            'topic' => $topic,
            'payload' => $payload,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $jsonData = json_decode($payload, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
            $tableMap = $this->matchTopic($topic, $topicMap);
            if ($tableMap) {
                $row = ['topic' => $topic, 'created_at' => date('Y-m-d H:i:s')];
                $valid = true;
                foreach ($tableMap['fields'] as $field) {
                    if (isset($jsonData[$field])) {
                        $row[$field] = $jsonData[$field];
                    } elseif (in_array($field, $tableMap['required'])) {
                        $valid = false;
                        break;
                    }
                }
                if ($valid) {
                    $row['table'] = $tableMap['table'];
                    return $row;
                }
            }
        }

        $data['table'] = $defaultTable;
        return $data;
    }

    protected function matchTopic(string $topic, array $topicMap)
    {
        foreach ($topicMap as $pattern => $map) {
            $patternParts = explode('/', $pattern);
            $topicParts = explode('/', $topic);
            $match = true;
            for ($i = 0; $i < count($patternParts); $i++) {
                if ($patternParts[$i] == '#') break;
                if ($patternParts[$i] != '+' && (!isset($topicParts[$i]) || $patternParts[$i] != $topicParts[$i])) {
                    $match = false;
                    break;
                }
            }
            if ($match) return $map;
        }
        return null;
    }
}