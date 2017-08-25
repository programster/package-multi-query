<?php

/*
 * Object to simplify execution of mysqli_multi_query which is used to bundle database queries
 * together. This greatly increases the performance of an application by reducing the overhead of
 * constantly connecting to the database.
 * 
 * Simply create the MultiQuery object by passing it a created mysqli resource connection, add 
 * multiple queries by calling addQuery(), and then when ready, execute it with run(). All the 
 * results can be fetched with getResult() and passing it the index of the query. (make sure to
 * remember to start counting from 0!)
 * 
 * In cases where you want to perform a simple 'join' of multiple tables that have the same
 * structure, use getMergedResult() to merge the results of all the queries into a single resultset.
 * 
 * Add queries.
 * Run it once
 * Get the result for the specific query by entering the index 
 */

namespace iRAP\MultiQuery;

class MultiQuery
{
    const STATE_NOT_SENT = 0; # multi query has not been sent
    const STATE_SUCCEEDED = 1; # multi query sent and succeeded
    const STATE_ERRORS = 2; # multiquery sent but had errors.
    
    /* @var $m_connection \mysqli */
    private $m_connection;
    private $m_results = array();
    private $m_queries = array();
    private $m_errors = array();
    private $m_status;
    
    public function __construct($mysqli)
    {
        $this->m_connection = $mysqli;
        $this->m_status = self::STATE_NOT_SENT;
    }
    
    
    /**
     * Add a query to the list of queries to send at once.
     * @param string $query - the SQL query to add to this multiquery. E.g. "SHOW TABLES";
     * @return int - the index of the query that you just added. You can use this later to get
     *               the result for that query from this objects results.
     */
    public function addQuery($query)
    {
        $this->m_queries[] = $query;
        return count($this->m_queries) - 1;
    }
    
    
    /**
     * After having added all the queries you want to execute, call this method to execute the 
     * queries on the database and retrieve the results. The code below can be a little confusing
     * because mysqli_store_result will return false if the query succeeded but did not return
     * a resultset, so you need to use this with the return from next_result() which will tell
     * you whether the query succeeded or failed (1 or false)
     */
    public function run()
    {
        $queries_string = implode(';', $this->m_queries);
        $resultIndex = 0;
        
        do
        {
            if ($resultIndex === 0)
            {
                $resultBool = mysqli_multi_query($this->m_connection, $queries_string);
            }
            else
            {
                $resultBool = $this->m_connection->next_result();
            }
            
            $errorStr = mysqli_error($this->m_connection);
            
            if ($errorStr !== "")
            {
                $this->m_errors[$resultIndex] = $errorStr;
            }
            
            // Dont forget that unlike mysqli->query, mysqli_store_result returns false for any 
            // queries that did not return a resultset, such as an insert statement.
            $resultSet = mysqli_store_result($this->m_connection);
            
            if ($resultSet === FALSE) # first query may not have returned a resultset, just a true or false.
            {
                $this->m_results[$resultIndex] = false;
            }
            else
            {
                $this->m_results[$resultIndex] = $resultSet;
            }
            
            $resultIndex++;
        } while($this->m_connection->more_results());
        
        if (count($this->m_errors) > 0)
        {
            $this->m_status = self::STATE_ERRORS;
        }
        else
        {
            $this->m_status = self::STATE_SUCCEEDED;
        }
    }
    
    
    /**
     * This acts similar to having run a JOIN (but means that you dont have to use db CPU)
     * It will perform a straight forward merge of the results. Beware that this has no clever
     * handling of columns that exist in some datasets and not others, they will just be not set.
     * WARNING: This method will chew through memory and is very inefficient, you may wwant to use
     * get_results() instead.
     */ 
    public function get_merged_result()
    {
        if (count($this->m_errors) > 0)
        {
            throw new \Exception("Cannot get merged result as there were errors in your multiquery.");
        }
        
        $masterSet = array();
        
        foreach ($this->m_results as $resultSet)
        {
            while (($row = $resultSet->fetch_assoc()) != null)
            {
                $masterSet[] = $row;
            }
        }
        
        return $masterSet;
    }
    
    
    /**
     * Fetches the result from one of the executed queries.
     * @param int $index - the index of the query that was sent that we want the result of. E.g. 
     *                     the order of the query as it was added to this object, starting from 0
     * @return Array - a list of assoc arrays representing rows in the database. (e.g. putting
     *                 all of fetch_assoc() into an array)
     * @throws Exception if there is no result for the specified index.
     */
    public function getResult($index)
    {
        if (count($this->m_errors) > 0)
        {
            throw new \Exception("Cannot get result as there were errors in your multiquery.");
        }
        
        if (isset($this->m_results[$index]))
        {
            return $this->m_results[$index];
        }
        else
        {
            throw new \Exception('There is no result for that index.');
        }
    }
    
    
    /**
     * Return all of the results for the queries. This will require a bit more work than 
     * get_merged_result() but is far more memory efficient.
     * @return array - array list of mysqli result sets that will need looping through.
     */
    public function get_results() 
    {
        if (count($this->m_errors) > 0)
        {
            throw new \Exception("Cannot get results as there were errors in your multiquery.");
        }
        
        return $this->m_results;
    }
    
    
    /**
     * Return all of the errors for the queries. 
     * @return array - list of errors indexed by the query id.
     */
    public function get_errors() 
    {
        return $this->m_errors;
    }
    
    
    /**
     * Return the number of errors there were
     * @return int - the number of errors there were.
     */
    public function get_error_count() 
    {
        return count($this->m_errors);
    }
    
    
    /**
     * Get the status of this multi query.
     * @return int
     */
    public function getStatus()
    {
        return $this->m_status;
    }
}
